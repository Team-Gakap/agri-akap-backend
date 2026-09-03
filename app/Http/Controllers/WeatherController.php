<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\SmsBroadcast;
use App\Models\WeatherCache;
use App\Models\WeatherCurrent;
use App\Models\WeatherHistorical;
use App\Models\WeatherHourly;
use App\Services\BagyoAdvisoryService;
use App\Services\PagasaRadarService;
use App\Services\WeatherAlertService;
use App\Services\WeatherHistoricalService;
use App\Services\WeatherHourlyService;
use App\Services\WeatherService;
use App\Traits\LogsReportAudit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WeatherController extends Controller
{
    use LogsReportAudit;

    public function __construct(
        private WeatherAlertService $weatherAlerts,
        private PagasaRadarService $pagasaRadar,
        private BagyoAdvisoryService $bagyoAdvisories,
    ) {
    }

    /**
     * Today's weather + upcoming 3-day forecast for a barangay (hyper-local).
     * Query: ?barangay=San%20Fabian  (defaults to Soyung / municipal proxy)
     */
    public function current(Request $request): JsonResponse
    {
        $barangay = $request->query('barangay');
        if (! is_string($barangay) || trim($barangay) === '') {
            $barangay = 'Soyung (Poblacion)';
        }

        $today = Carbon::now(WeatherService::TIMEZONE)->toDateString();
        $end = Carbon::now(WeatherService::TIMEZONE)->addDays(3)->toDateString();

        $rows = WeatherCache::query()
            ->where('barangay_name', $barangay)
            ->whereBetween('forecast_date', [$today, $end])
            ->orderBy('forecast_date')
            ->get();

        // Fallback: if selected barangay has no cache yet, use any available barangay's today row set.
        if ($rows->isEmpty()) {
            $fallbackName = WeatherCache::query()
                ->whereDate('forecast_date', $today)
                ->value('barangay_name');
            if ($fallbackName) {
                $barangay = $fallbackName;
                $rows = WeatherCache::query()
                    ->where('barangay_name', $barangay)
                    ->whereBetween('forecast_date', [$today, $end])
                    ->orderBy('forecast_date')
                    ->get();
            }
        }

        $coords = Barangay::query()->where('name', $barangay)->first();

        $todayRow = $rows->first(fn (WeatherCache $row) => Carbon::parse($row->forecast_date)->toDateString() === $today);
        $forecast = $rows
            ->filter(fn (WeatherCache $row) => Carbon::parse($row->forecast_date)->toDateString() > $today)
            ->values()
            ->take(3)
            ->map(fn (WeatherCache $row) => $this->transform($row))
            ->all();

        return response()->json([
            'data' => [
                'location' => [
                    'municipality' => 'Echague',
                    'province' => 'Isabela',
                    'barangay' => $barangay,
                    'latitude' => $coords ? (float) $coords->latitude : WeatherService::LATITUDE,
                    'longitude' => $coords ? (float) $coords->longitude : WeatherService::LONGITUDE,
                    'timezone' => WeatherService::TIMEZONE,
                ],
                'today' => $todayRow ? $this->transform($todayRow) : null,
                'forecast' => $forecast,
            ],
        ]);
    }

    /**
     * List barangays available for the weather dropdown.
     */
    public function barangays(): JsonResponse
    {
        $names = Barangay::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['data' => $names]);
    }

    /**
     * Reverse-geocode GPS to a real place name (works anywhere, not just Echague).
     * Query: ?lat=16.71&lng=121.66
     *
     * Uses OpenStreetMap Nominatim. Optionally includes nearest Echague
     * barangay pin when the technician is within ~8 km of a seeded pin.
     */
    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $place = $this->reverseGeocodeNominatim($lat, $lng);

        if (! $place) {
            return response()->json([
                'status' => 'error',
                'message' => 'Could not resolve location name for these coordinates.',
            ], 502);
        }

        $nearestEchague = $this->nearestEchagueBarangay($lat, $lng, 8.0);

        return response()->json([
            'data' => [
                'place' => $place['place'],
                'locality' => $place['locality'],
                'municipality' => $place['municipality'],
                'province' => $place['province'],
                'country' => $place['country'],
                'display_name' => $place['display_name'],
                'in_echague' => $nearestEchague !== null
                    || str_contains(mb_strtolower($place['municipality'] ?? ''), 'echague'),
                'nearest_echague_barangay' => $nearestEchague,
            ],
        ]);
    }

    /**
     * Resolve device GPS to the nearest Echague barangay pin.
     * Query: ?lat=16.71&lng=121.66
     */
    public function nearest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $nearest = $this->nearestEchagueBarangay($lat, $lng);

        if (! $nearest) {
            return response()->json([
                'status' => 'error',
                'message' => 'No barangay pins found. Run barangay seeders first.',
            ], 404);
        }

        return response()->json(['data' => $nearest]);
    }

    /**
     * Next 6 hourly slots for a barangay (6-Hour Action Window).
     * Path: /api/weather/hourly/{barangay_name}
     */
    public function hourly(string $barangay_name): JsonResponse
    {
        $barangay = trim(urldecode($barangay_name));
        if ($barangay === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Barangay name is required.',
            ], 422);
        }

        $now = Carbon::now(WeatherHourlyService::TIMEZONE);

        $rows = WeatherHourly::query()
            ->where('barangay_name', $barangay)
            ->where('forecast_datetime', '>=', $now)
            ->orderBy('forecast_datetime')
            ->limit(6)
            ->get()
            ->map(fn (WeatherHourly $row) => $this->transformHourly($row))
            ->values();

        return response()->json([
            'data' => [
                'barangay' => $barangay,
                'timezone' => WeatherHourlyService::TIMEZONE,
                'generated_at' => $now->toIso8601String(),
                'hours' => $rows,
            ],
        ]);
    }

    /**
     * Last ~30 days of daily climate for a barangay (Chart.js historical series).
     * Path: /api/weather/historical/{barangay_name}
     * Ordered oldest → newest for chronological plotting.
     */
    public function historical(string $barangay_name): JsonResponse
    {
        $barangay = trim(urldecode($barangay_name));
        if ($barangay === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Barangay name is required.',
            ], 422);
        }

        $rows = WeatherHistorical::query()
            ->where('barangay_name', $barangay)
            ->orderBy('date')
            ->get()
            ->map(fn (WeatherHistorical $row) => $this->transformHistorical($row))
            ->values();

        return response()->json([
            'data' => [
                'barangay' => $barangay,
                'timezone' => WeatherHistoricalService::TIMEZONE,
                'days' => $rows,
            ],
        ]);
    }

    /**
     * Heatmap payload: one row per barangay for a forecast date (default: today).
     * Query: ?date=YYYY-MM-DD
     */
    public function heatmap(Request $request): JsonResponse
    {
        $date = $request->query('date');
        if (! is_string($date) || trim($date) === '') {
            $date = Carbon::now(WeatherService::TIMEZONE)->toDateString();
        }

        $now = Carbon::now(WeatherHourlyService::TIMEZONE);
        $windowEnd = $now->copy()->addHours(6);

        $hourlyAgg = WeatherHourly::query()
            ->where('forecast_datetime', '>=', $now)
            ->where('forecast_datetime', '<', $windowEnd)
            ->selectRaw('barangay_name, MAX(precipitation_probability) as precip_next_6h')
            ->groupBy('barangay_name')
            ->pluck('precip_next_6h', 'barangay_name');

        $currentByBarangay = WeatherCurrent::query()
            ->get()
            ->keyBy('barangay_name');

        $pins = Barangay::query()
            ->where('is_active', true)
            ->get(['name', 'latitude', 'longitude'])
            ->keyBy('name');

        $rows = WeatherCache::query()
            ->whereDate('forecast_date', $date)
            ->orderBy('barangay_name')
            ->get()
            ->map(function (WeatherCache $row) use ($pins, $hourlyAgg, $currentByBarangay) {
                $payload = $this->transform($row);
                $payload['precipitation_probability_daily'] = $row->precipitation_probability;
                $next6h = $hourlyAgg->get($row->barangay_name);
                $payload['precipitation_probability_next_6h'] = $next6h !== null
                    ? (int) $next6h
                    : null;

                $current = $currentByBarangay->get($row->barangay_name);
                $payload['current_conditions'] = $current
                    ? $this->transformCurrent($current)
                    : null;

                $pin = $pins->get($row->barangay_name);
                $payload['latitude'] = $pin !== null ? (float) $pin->latitude : null;
                $payload['longitude'] = $pin !== null ? (float) $pin->longitude : null;

                return $payload;
            })
            ->values();

        $dailySynced = WeatherCache::query()->max('updated_at');
        $hourlySynced = WeatherHourly::query()->max('updated_at');
        $currentSynced = WeatherCurrent::query()->max('updated_at');

        return response()->json([
            'data' => [
                'forecast_date' => $date,
                'barangays' => $rows,
                'meta' => [
                    'timezone' => WeatherService::TIMEZONE,
                    'daily_synced_at' => $dailySynced
                        ? Carbon::parse($dailySynced, WeatherService::TIMEZONE)->toIso8601String()
                        : null,
                    'hourly_synced_at' => $hourlySynced
                        ? Carbon::parse($hourlySynced, WeatherHourlyService::TIMEZONE)->toIso8601String()
                        : null,
                    'current_synced_at' => $currentSynced
                        ? Carbon::parse($currentSynced, WeatherHourlyService::TIMEZONE)->toIso8601String()
                        : null,
                    'generated_at' => $now->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * PAGASA Panahon radar frames for map overlay.
     * Query: ?product=mosaic-qpe
     */
    public function radar(Request $request): JsonResponse
    {
        $product = $request->query('product', 'mosaic-qpe');
        if (! is_string($product) || trim($product) === '') {
            $product = 'mosaic-qpe';
        }

        return response()->json([
            'data' => $this->pagasaRadar->timeline($product),
        ]);
    }

    /**
     * National rainfall / cyclone advisories (Bagyo API proxy).
     */
    public function nationalAdvisories(): JsonResponse
    {
        return response()->json([
            'data' => $this->bagyoAdvisories->nationalAdvisories(),
        ]);
    }

    /**
     * Radar QPE at a barangay pin. Query: ?lat=&lng=
     */
    public function radarPoint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'product' => ['nullable', 'string', 'max:64'],
        ]);

        $product = $validated['product'] ?? 'mosaic-qpe';
        $value = $this->pagasaRadar->pointValue(
            (float) $validated['lat'],
            (float) $validated['lng'],
            is_string($product) ? $product : 'mosaic-qpe'
        );

        return response()->json([
            'data' => [
                'rainfall_mm_hr' => $value,
                'product' => is_string($product) ? $product : 'mosaic-qpe',
                'attribution' => 'PAGASA / DOST via Panahon',
            ],
        ]);
    }

    /**
     * Suggested hyper-local weather SMS copy for the Admin Broadcast Center.
     */
    public function advisories(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->weatherAlerts->suggestedAdvisories(),
        ]);
    }

    /**
     * Manually dispatch hyper-local weather warnings now (Admin trigger).
     */
    public function sendAdvisory(Request $request): JsonResponse
    {
        $result = $this->weatherAlerts->evaluateAndSend(
            force: true,
            triggerType: SmsBroadcast::TRIGGER_MANUAL
        );

        if (! $result['sent']) {
            return response()->json([
                'status' => 'error',
                'message' => $result['skipped'] ?? 'No weather advisory to send.',
                'data' => $result,
            ], 422);
        }

        $this->logReportAudit('weather.advisory.sent', null, [
            'after' => [
                'alerts_sent' => $result['alerts_sent'] ?? null,
                'recipient_count' => $result['recipient_count'] ?? null,
            ],
        ]);

        $mockNote = $result['mocked'] ? ' (mocked in local — no SMS charge)' : '';

        return response()->json([
            'status' => 'success',
            'message' => "Weather warnings sent for {$result['alerts_sent']} barangay(s) to {$result['recipient_count']} farmer(s).{$mockNote}",
            'data' => $result,
        ]);
    }

    protected function transform(WeatherCache $row): array
    {
        $code = $row->weather_code;

        return [
            'id' => $row->id,
            'barangay_name' => $row->barangay_name,
            'forecast_date' => Carbon::parse($row->forecast_date)->toDateString(),
            'temperature_min' => $row->temperature_min !== null ? (float) $row->temperature_min : null,
            'temperature_max' => $row->temperature_max !== null ? (float) $row->temperature_max : null,
            'precipitation_probability' => $row->precipitation_probability,
            'soil_moisture' => $row->soil_moisture !== null ? (float) $row->soil_moisture : null,
            'evapotranspiration' => $row->evapotranspiration !== null ? (float) $row->evapotranspiration : null,
            'soil_moisture_28cm' => $row->soil_moisture_28cm !== null ? (float) $row->soil_moisture_28cm : null,
            'wind_speed_10m' => $row->wind_speed_10m !== null ? (float) $row->wind_speed_10m : null,
            'weather_code' => $code,
            'status' => $this->statusFromCode($code),
        ];
    }

    protected function transformHourly(WeatherHourly $row): array
    {
        $code = $row->weather_code;

        return [
            'id' => $row->id,
            'barangay_name' => $row->barangay_name,
            'forecast_datetime' => $row->forecast_datetime->timezone(WeatherHourlyService::TIMEZONE)->toIso8601String(),
            'temperature' => $row->temperature !== null ? (float) $row->temperature : null,
            'precipitation_probability' => $row->precipitation_probability,
            'wind_speed' => $row->wind_speed !== null ? (float) $row->wind_speed : null,
            'weather_code' => $code,
            'status' => $this->statusFromCode($code),
        ];
    }

    protected function transformHistorical(WeatherHistorical $row): array
    {
        return [
            'id' => $row->id,
            'barangay_name' => $row->barangay_name,
            'date' => Carbon::parse($row->date)->toDateString(),
            'precipitation_sum' => $row->precipitation_sum !== null ? (float) $row->precipitation_sum : null,
            'temperature_max' => $row->temperature_max !== null ? (float) $row->temperature_max : null,
            'et0_fao_evapotranspiration' => $row->et0_fao_evapotranspiration !== null
                ? (float) $row->et0_fao_evapotranspiration
                : null,
        ];
    }

    protected function transformCurrent(WeatherCurrent $row): array
    {
        $code = $row->weather_code;

        return [
            'barangay_name' => $row->barangay_name,
            'observed_at' => $row->observed_at->timezone(WeatherHourlyService::TIMEZONE)->toIso8601String(),
            'temperature' => $row->temperature !== null ? (float) $row->temperature : null,
            'precipitation' => $row->precipitation !== null ? (float) $row->precipitation : null,
            'rain' => $row->rain !== null ? (float) $row->rain : null,
            'precipitation_probability' => $row->precipitation_probability,
            'wind_speed' => $row->wind_speed !== null ? (float) $row->wind_speed : null,
            'weather_code' => $code,
            'status' => $this->statusFromCode($code),
            'source' => 'Open-Meteo model now-cast',
        ];
    }

    protected function statusFromCode(?int $code): string
    {
        if ($code === null) {
            return 'Unknown';
        }

        return match (true) {
            $code === 0 => 'Clear',
            $code <= 3 => 'Partly Cloudy',
            $code <= 48 => 'Fog',
            $code <= 57 => 'Drizzle',
            $code <= 67 => 'Rain',
            $code <= 77 => 'Snow',
            $code <= 82 => 'Rain Showers',
            $code <= 86 => 'Snow Showers',
            $code >= 95 => 'Thunderstorm',
            default => 'Overcast',
        };
    }

    protected function reverseGeocodeNominatim(float $lat, float $lng): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AGRI-AKAP/1.0 (Municipal Agriculture Office Echague; contact=mao@echague.local)',
                'Accept-Language' => 'en',
            ])->timeout(12)->get('https://nominatim.openstreetmap.org/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'json',
                'addressdetails' => 1,
                'zoom' => 16,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            $address = is_array($json['address'] ?? null) ? $json['address'] : [];

            $locality = $this->firstAddressValue($address, [
                'suburb', 'village', 'neighbourhood', 'quarter', 'hamlet',
                'residential', 'city_district', 'district',
            ]);
            $municipality = $this->firstAddressValue($address, [
                'city', 'town', 'municipality', 'city_district',
            ]);
            $province = $this->firstAddressValue($address, [
                'state', 'province', 'region', 'county',
            ]);
            $country = $this->firstAddressValue($address, ['country']) ?? 'Philippines';

            $place = $locality
                ?? $municipality
                ?? $province
                ?? ($json['display_name'] ?? null);

            if (! is_string($place) || trim($place) === '') {
                return null;
            }

            return [
                'place' => $place,
                'locality' => $locality,
                'municipality' => $municipality,
                'province' => $province,
                'country' => $country,
                'display_name' => is_string($json['display_name'] ?? null)
                    ? $json['display_name']
                    : $place,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  array<int, string>  $keys
     */
    protected function firstAddressValue(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array{barangay:string,municipality:string,province:string,distance_km:float,latitude:float,longitude:float}|null
     */
    protected function nearestEchagueBarangay(float $lat, float $lng, ?float $maxKm = null): ?array
    {
        $nearest = null;
        $bestKm = PHP_FLOAT_MAX;

        foreach (Barangay::query()->where('is_active', true)->get(['name', 'latitude', 'longitude']) as $brgy) {
            $km = $this->haversineKm($lat, $lng, (float) $brgy->latitude, (float) $brgy->longitude);
            if ($km < $bestKm) {
                $bestKm = $km;
                $nearest = $brgy;
            }
        }

        if (! $nearest) {
            return null;
        }

        if ($maxKm !== null && $bestKm > $maxKm) {
            return null;
        }

        return [
            'barangay' => $nearest->name,
            'municipality' => 'Echague',
            'province' => 'Isabela',
            'distance_km' => round($bestKm, 2),
            'latitude' => (float) $nearest->latitude,
            'longitude' => (float) $nearest->longitude,
        ];
    }

    protected function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthKm * asin(min(1, sqrt($a)));
    }
}
