<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\WeatherCache;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WeatherService
{
    /** Soyung (Poblacion) field pin — fallback / display default. */
    public const LATITUDE = 16.701478906510456;

    public const LONGITUDE = 121.66391107686333;

    public const TIMEZONE = 'Asia/Manila';

    public const CHUNK_SIZE = 30;

    protected const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    protected const HTTP_TIMEOUT_SECONDS = 60;

    protected const CONNECT_TIMEOUT_SECONDS = 20;

    protected const MAX_ATTEMPTS = 3;

    /**
     * Bulk-fetch Open-Meteo for every active barangay (chunked) and upsert a 7-day cache.
     *
     * @return array{synced:int, barangays:int, chunks:int, dates:array<int,string>}
     */
    public function fetchAndCache(): array
    {
        $barangays = Barangay::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'latitude', 'longitude']);

        if ($barangays->isEmpty()) {
            throw new RuntimeException('No barangays found. Run: php artisan db:seed --class=BarangayCoordinateSeeder');
        }

        $synced = 0;
        $dates = [];
        $chunks = 0;

        foreach ($barangays->chunk(self::CHUNK_SIZE) as $chunk) {
            $chunks++;
            $synced += $this->fetchChunk($chunk, $dates);
        }

        $this->pruneStaleWindow();

        return [
            'synced' => $synced,
            'barangays' => $barangays->count(),
            'chunks' => $chunks,
            'dates' => array_values(array_unique($dates)),
        ];
    }

    /**
     * @param  Collection<int, Barangay>  $chunk
     * @param  array<int, string>  $dates
     */
    protected function fetchChunk(Collection $chunk, array &$dates): int
    {
        $lats = $chunk->pluck('latitude')->map(fn ($v) => (string) $v)->implode(',');
        $lngs = $chunk->pluck('longitude')->map(fn ($v) => (string) $v)->implode(',');

        $response = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get(self::FORECAST_URL, [
                        'latitude' => $lats,
                        'longitude' => $lngs,
                        'daily' => 'weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,et0_fao_evapotranspiration,windspeed_10m_max',
                        'hourly' => 'soil_moisture_7_to_28cm',
                        'timezone' => self::TIMEZONE,
                        'forecast_days' => 7,
                    ]);

                if ($response->successful()) {
                    break;
                }

                $lastError = 'HTTP '.$response->status();
                Log::warning('Open-Meteo daily attempt failed', [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'count' => $chunk->count(),
                ]);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('Open-Meteo daily connection attempt failed', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                    'count' => $chunk->count(),
                ]);
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(500_000 * $attempt);
            }
        }

        if ($response === null || ! $response->successful()) {
            Log::error('Open-Meteo bulk fetch failed', [
                'error' => $lastError,
                'count' => $chunk->count(),
            ]);
            throw new RuntimeException('Unable to fetch Open-Meteo forecast ('.$lastError.').');
        }

        $payload = $response->json();

        // Single-location responses are objects; multi-location are arrays.
        $locations = $this->normalizeLocations($payload);
        if (count($locations) !== $chunk->count()) {
            Log::warning('Open-Meteo location count mismatch', [
                'expected' => $chunk->count(),
                'received' => count($locations),
            ]);
        }

        $synced = 0;
        $barangayList = $chunk->values();

        foreach ($barangayList as $index => $barangay) {
            $location = $locations[$index] ?? null;
            if (! is_array($location)) {
                continue;
            }

            $synced += $this->upsertLocationForecast($barangay->name, $location, $dates);
        }

        return $synced;
    }

    /**
     * @param  array<string,mixed>|list<array<string,mixed>>  $payload
     * @return list<array<string,mixed>>
     */
    protected function normalizeLocations(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        // Multi-location: list of location objects (numeric keys).
        if (array_is_list($payload)) {
            return $payload;
        }

        // Single-location object.
        if (isset($payload['daily'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $location
     * @param  array<int, string>  $dates
     */
    protected function upsertLocationForecast(string $barangayName, array $location, array &$dates): int
    {
        $daily = $location['daily'] ?? null;
        if (! is_array($daily) || empty($daily['time']) || ! is_array($daily['time'])) {
            return 0;
        }

        $soil28 = $this->averageSoilMoistureByDate(
            $location['hourly'] ?? [],
            'soil_moisture_7_to_28cm'
        );

        $synced = 0;

        foreach ($daily['time'] as $index => $date) {
            $dates[] = $date;

            WeatherCache::updateOrCreate(
                [
                    'barangay_name' => $barangayName,
                    'forecast_date' => $date,
                ],
                [
                    'temperature_min' => $daily['temperature_2m_min'][$index] ?? null,
                    'temperature_max' => $daily['temperature_2m_max'][$index] ?? null,
                    'precipitation_probability' => isset($daily['precipitation_probability_max'][$index])
                        ? (int) round((float) $daily['precipitation_probability_max'][$index])
                        : null,
                    'precipitation_sum' => isset($daily['precipitation_sum'][$index])
                        ? round((float) $daily['precipitation_sum'][$index], 2)
                        : null,
                    'soil_moisture' => null,
                    'evapotranspiration' => $daily['et0_fao_evapotranspiration'][$index] ?? null,
                    'soil_moisture_28cm' => $soil28[$date] ?? null,
                    'wind_speed_10m' => $daily['windspeed_10m_max'][$index]
                        ?? $daily['wind_speed_10m_max'][$index]
                        ?? null,
                    'weather_code' => isset($daily['weathercode'][$index])
                        ? (int) $daily['weathercode'][$index]
                        : (isset($daily['weather_code'][$index]) ? (int) $daily['weather_code'][$index] : null),
                ]
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * @param  array<string,mixed>  $hourly
     * @return array<string,float>
     */
    protected function averageSoilMoistureByDate(array $hourly, string $field): array
    {
        $times = $hourly['time'] ?? [];
        $values = $hourly[$field] ?? [];

        if (! is_array($times) || ! is_array($values)) {
            return [];
        }

        $buckets = [];
        foreach ($times as $index => $timestamp) {
            $value = $values[$index] ?? null;
            if ($value === null) {
                continue;
            }
            $date = Carbon::parse($timestamp, self::TIMEZONE)->toDateString();
            $buckets[$date][] = (float) $value;
        }

        $averages = [];
        foreach ($buckets as $date => $samples) {
            $averages[$date] = round(array_sum($samples) / max(count($samples), 1), 3);
        }

        return $averages;
    }

    protected function pruneStaleWindow(): void
    {
        $windowStart = Carbon::now(self::TIMEZONE)->startOfDay()->toDateString();
        $windowEnd = Carbon::now(self::TIMEZONE)->addDays(6)->toDateString();

        WeatherCache::query()
            ->where(function ($query) use ($windowStart, $windowEnd) {
                $query->where('forecast_date', '<', $windowStart)
                    ->orWhere('forecast_date', '>', $windowEnd);
            })
            ->delete();
    }
}
