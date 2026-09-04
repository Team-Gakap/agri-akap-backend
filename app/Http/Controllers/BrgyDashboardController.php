<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\HarvestLog;
use App\Models\PestMonitoring;
use App\Models\SubsidyBeneficiary;
use App\Models\WeatherCache;
use App\Models\WeatherHourly;
use App\Services\CropStageService;
use App\Services\WeatherHourlyService;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BrgyDashboardController extends Controller
{
    /**
     * Localized 4-tier Command Center for the logged-in barangay official.
     * GET /api/brgy/dashboard
     *
     * All aggregates are strictly scoped to auth()->user()->assigned_barangay.
     */
    public function index(): JsonResponse
    {
        $barangay = auth()->user()?->assigned_barangay;

        if (empty($barangay)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No barangay assignment on this account.',
            ], 403);
        }

        $weatherKey = $this->resolveWeatherBarangay($barangay);
        $hourlyForecast = $this->hourlyForecast($weatherKey);

        return response()->json([
            'data' => [
                'barangay' => $barangay,
                'descriptive' => $this->descriptive($barangay),
                'diagnostic' => [
                    'current_weather' => $this->currentWeather($weatherKey, $hourlyForecast),
                    'crop_stages' => $this->cropStages($barangay),
                    'monthly_yield_damage' => $this->monthlyYieldDamage($barangay),
                ],
                'predictive' => [
                    'hourly_forecast' => $hourlyForecast,
                ],
                'prescriptive' => [
                    'alerts' => $this->prescriptiveAlerts($barangay, $hourlyForecast),
                ],
            ],
        ]);
    }

    /**
     * Descriptive KPIs for barangay workers.
     *
     * @return array<string, int|float>
     */
    private function descriptive(string $barangay): array
    {
        $farmers = Farmer::query()->where('permanent_brgy', $barangay);
        $totalFarmers = (clone $farmers)->count();
        $verifiedFarmers = (clone $farmers)
            ->whereNotNull('rsbsa_no')
            ->where('rsbsa_no', '!=', '')
            ->count();
        $pendingFarmers = $totalFarmers - $verifiedFarmers;

        $registeredLandHa = (float) (clone $farmers)->sum('total_farm_area_ha');
        $planted = $this->activePlantedHectares($barangay);
        $totalPlanted = $planted['rice'] + $planted['corn'] + $planted['other'];
        $activeCalamities = $this->pendingCalamities($barangay);
        $activePests = $this->unverifiedPests($barangay);

        $subsidyBase = SubsidyBeneficiary::query()
            ->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay));
        $claimedSubsidies = (clone $subsidyBase)->where('status', 'Claimed')->count();
        $unclaimedSubsidies = (clone $subsidyBase)->where('status', 'Pending')->count();

        return [
            'total_farmers' => $totalFarmers,
            'verified_farmers' => $verifiedFarmers,
            'pending_farmers' => $pendingFarmers,
            'missing_id_photos' => (clone $farmers)
                ->where(function ($q) {
                    $q->whereNull('photo_path')->orWhere('photo_path', '');
                })
                ->count(),
            'total_hectares' => round($registeredLandHa, 2),
            'rice_hectares' => round($planted['rice'], 2),
            'corn_hectares' => round($planted['corn'], 2),
            'active_hectares' => round($totalPlanted, 2),
            'active_planted_ha' => round($totalPlanted, 2),
            'active_rice_ha' => round($planted['rice'], 2),
            'active_corn_ha' => round($planted['corn'], 2),
            'registered_land_ha' => round($registeredLandHa, 2),
            'registered_rice_ha' => null,
            'registered_corn_ha' => null,
            'tilled_percent' => $registeredLandHa > 0
                ? round($totalPlanted / $registeredLandHa * 100)
                : 0,
            'claimed_subsidies' => $claimedSubsidies,
            'unclaimed_subsidies' => $unclaimedSubsidies,
            'pending_subsidies' => $unclaimedSubsidies,
            'active_calamities' => $activeCalamities,
            'active_pests' => $activePests,
            'active_threats' => $activeCalamities + $activePests,
        ];
    }

    /**
     * Registered parcel area for this barangay, grouped by commodity.
     *
     * @return array{rice: float, corn: float, other: float}
     */
    private function plotHectares(string $barangay): array
    {
        $out = ['rice' => 0.0, 'corn' => 0.0, 'other' => 0.0];

        if (! Schema::hasTable('farm_plots')) {
            return $out;
        }

        $farmerIds = Farmer::query()->where('permanent_brgy', $barangay)->pluck('id');

        DB::table('farm_plots')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($barangay, $farmerIds) {
                $q->where('location_brgy', $barangay)
                    ->orWhereIn('farmer_id', $farmerIds);
            })
            ->selectRaw("COALESCE(NULLIF(commodity, ''), 'Other') as commodity")
            ->selectRaw('SUM(size_ha) as total_area_ha')
            ->groupByRaw('1')
            ->get()
            ->each(function ($row) use (&$out) {
                $normalized = strtolower(trim((string) $row->commodity));
                $key = match (true) {
                    str_contains($normalized, 'rice') => 'rice',
                    str_contains($normalized, 'corn') => 'corn',
                    default => 'other',
                };
                $out[$key] += (float) $row->total_area_ha;
            });

        return $out;
    }

    /**
     * Active planted area from planting_logs for this barangay, grouped by crop.
     *
     * @return array{rice: float, corn: float, other: float}
     */
    private function activePlantedHectares(string $barangay): array
    {
        $out = ['rice' => 0.0, 'corn' => 0.0, 'other' => 0.0];

        if (! Schema::hasTable('planting_logs')) {
            return $out;
        }

        $farmerIds = Farmer::query()->where('permanent_brgy', $barangay)->pluck('id');

        DB::table('planting_logs')
            ->where('status', 'Active')
            ->whereIn('farmer_id', $farmerIds)
            ->selectRaw("COALESCE(NULLIF(crop_type, ''), 'Other') as crop")
            ->selectRaw('SUM(area_planted) as total')
            ->groupByRaw('1')
            ->get()
            ->each(function ($row) use (&$out) {
                $normalized = strtolower(trim((string) $row->crop));
                $key = match (true) {
                    str_contains($normalized, 'rice') => 'rice',
                    str_contains($normalized, 'corn') => 'corn',
                    default => 'other',
                };
                $out[$key] += (float) $row->total;
            });

        return $out;
    }

    private function pendingCalamities(string $barangay): int
    {
        return DamageAssessment::query()
            ->where('status', 'Pending')
            ->where(function ($q) use ($barangay) {
                $q->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                    ->orWhereHas('farmPlot', fn ($plot) => $plot->where('location_brgy', $barangay));
            })
            ->count();
    }

    private function unverifiedPests(string $barangay): int
    {
        return $this->unverifiedPestQuery($barangay)->count();
    }

    /**
     * Active crop lifecycle mix: live planting stages first, then standing crops
     * (fallback: pest inspections) for plots without an active planting log.
     *
     * @return array{seedling: int, vegetative: int, reproductive: int, maturity: int}
     */
    private function cropStages(string $barangay): array
    {
        return app(CropStageService::class)->hybridStageTally($barangay);
    }

    /**
     * Last 12 months of harvest yield vs crop damage (hectares affected).
     * Harvest is scoped like the ledger: farmer barangay, farm_location, or plot barangay.
     * Yield older than the window still lands in the current month so the bar matches the ledger total.
     *
     * @return array<int, array{month: string, key: string, harvest: float, damage: float}>
     */
    private function monthlyYieldDamage(string $barangay): array
    {
        $now = Carbon::now(WeatherService::TIMEZONE)->startOfMonth();
        $start = $now->copy()->subMonths(11);
        $currentKey = $now->format('Y-m');
        $buckets = [];

        for ($i = 0; $i < 12; $i++) {
            $cursor = $start->copy()->addMonths($i);
            $key = $cursor->format('Y-m');
            $buckets[$key] = [
                'month' => $cursor->format('M'),
                'key' => $key,
                'harvest' => 0.0,
                'damage' => 0.0,
            ];
        }

        if (Schema::hasTable('harvest_logs')) {
            HarvestLog::query()
                ->where(function ($q) use ($barangay) {
                    $q->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                        ->orWhere('farm_location', $barangay)
                        ->orWhereHas('farmPlot', fn ($plot) => $plot->where('location_brgy', $barangay));
                })
                ->get(['date_harvested', 'total_yield', 'yield_unit'])
                ->each(function (HarvestLog $row) use (&$buckets, $currentKey) {
                    $key = optional($row->date_harvested)?->format('Y-m');
                    if (! $key || ! isset($buckets[$key])) {
                        $key = $currentKey;
                    }
                    if (isset($buckets[$key])) {
                        $buckets[$key]['harvest'] += $this->yieldToMetricTons(
                            (float) $row->total_yield,
                            (string) ($row->yield_unit ?? '')
                        );
                    }
                });
        }

        if (Schema::hasTable('damage_assessments')) {
            DamageAssessment::query()
                ->where(function ($q) use ($barangay) {
                    $q->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                        ->orWhereHas('farmPlot', fn ($plot) => $plot->where('location_brgy', $barangay));
                })
                ->whereDate('date_of_calamity', '>=', $start->toDateString())
                ->with('farmPlot:id,size_ha')
                ->get(['date_of_calamity', 'area_destroyed_ha', 'area_planted_ha', 'damage_percentage', 'farm_plot_id', 'created_at'])
                ->each(function (DamageAssessment $row) use (&$buckets) {
                    $date = $row->date_of_calamity ?? $row->created_at;
                    $key = optional($date)?->format('Y-m');
                    if ($key && isset($buckets[$key])) {
                        $ha = (float) ($row->area_destroyed_ha ?? 0);
                        if ($ha <= 0) {
                            $base = (float) ($row->area_planted_ha ?? 0);
                            if ($base <= 0) {
                                $base = (float) ($row->farmPlot?->size_ha ?? 0);
                            }
                            $ha = $base * ((float) ($row->damage_percentage ?? 0) / 100);
                        }
                        $buckets[$key]['damage'] += $ha;
                    }
                });
        }

        return array_values(array_map(fn ($row) => [
            'month' => $row['month'],
            'key' => $row['key'],
            'harvest' => round($row['harvest'], 2),
            'harvest_unit' => 'MT',
            'damage' => round($row['damage'], 2),
            'damage_unit' => 'ha',
        ], $buckets));
    }

    private function yieldToMetricTons(float $amount, string $unit): float
    {
        $normalized = strtolower(trim($unit));
        if ($normalized === '' || str_contains($normalized, 'mt') || str_contains($normalized, 'ton')) {
            return $amount;
        }
        if (str_contains($normalized, 'kg')) {
            return $amount / 1000;
        }

        return $amount;
    }

    /**
     * Match assigned_barangay to a weather-cache name (case-insensitive, then LIKE).
     * Does not fall back to a different barangay — empty cache stays empty.
     */
    private function resolveWeatherBarangay(string $barangay): string
    {
        $needle = trim($barangay);
        if ($needle === '') {
            return $needle;
        }

        $lower = mb_strtolower($needle);

        $exact = WeatherCache::query()
            ->whereRaw('LOWER(barangay_name) = ?', [$lower])
            ->value('barangay_name');
        if ($exact) {
            return (string) $exact;
        }

        $hourlyExact = WeatherHourly::query()
            ->whereRaw('LOWER(barangay_name) = ?', [$lower])
            ->value('barangay_name');
        if ($hourlyExact) {
            return (string) $hourlyExact;
        }

        $like = WeatherCache::query()
            ->where('barangay_name', 'like', '%'.$needle.'%')
            ->value('barangay_name');
        if ($like) {
            return (string) $like;
        }

        $hourlyLike = WeatherHourly::query()
            ->where('barangay_name', 'like', '%'.$needle.'%')
            ->value('barangay_name');

        return $hourlyLike ? (string) $hourlyLike : $needle;
    }

    /**
     * Latest cached daily weather for this barangay (today, else most recent).
     * If the daily cache is empty, synthesize a snapshot from the first hourly slot.
     *
     * @param  array<int, array<string, mixed>>  $hourlyForecast
     */
    private function currentWeather(string $barangay, array $hourlyForecast = []): ?array
    {
        $today = Carbon::now(WeatherService::TIMEZONE)->toDateString();

        $row = WeatherCache::query()
            ->where('barangay_name', $barangay)
            ->whereDate('forecast_date', $today)
            ->first();

        if (! $row) {
            $row = WeatherCache::query()
                ->where('barangay_name', $barangay)
                ->orderByDesc('forecast_date')
                ->first();
        }

        if ($row) {
            return $this->transformWeatherCache($row);
        }

        $hour = $hourlyForecast[0] ?? null;
        if (! $hour) {
            return null;
        }

        return [
            'id' => $hour['id'] ?? null,
            'barangay_name' => $hour['barangay_name'] ?? $barangay,
            'forecast_date' => Carbon::parse($hour['forecast_datetime'] ?? 'now', WeatherService::TIMEZONE)->toDateString(),
            'temperature_min' => $hour['temperature'] ?? null,
            'temperature_max' => $hour['temperature'] ?? null,
            'precipitation_probability' => $hour['precipitation_probability'] ?? null,
            'soil_moisture' => null,
            'evapotranspiration' => null,
            'soil_moisture_28cm' => null,
            'wind_speed_10m' => $hour['wind_speed'] ?? null,
            'weather_code' => $hour['weather_code'] ?? null,
            'status' => $hour['status'] ?? 'Unknown',
            'from_hourly' => true,
        ];
    }

    /**
     * Next 6 hourly slots for this barangay (6-Hour Action Window).
     *
     * @return array<int, array<string, mixed>>
     */
    private function hourlyForecast(string $barangay): array
    {
        $now = Carbon::now(WeatherHourlyService::TIMEZONE);

        return WeatherHourly::query()
            ->where('barangay_name', $barangay)
            ->where('forecast_datetime', '>=', $now)
            ->orderBy('forecast_datetime')
            ->limit(6)
            ->get()
            ->map(fn (WeatherHourly $row) => $this->transformHourly($row))
            ->values()
            ->all();
    }

    /**
     * Action Center alerts: unverified pests, pending calamity, and 6-hour weather flags.
     *
     * @param  array<int, array<string, mixed>>  $hourlyForecast
     * @return array<int, array<string, mixed>>
     */
    private function prescriptiveAlerts(string $barangay, array $hourlyForecast): array
    {
        $alerts = [];

        $pest = $this->unverifiedPestQuery($barangay)->orderByDesc('created_at')->first();
        if ($pest) {
            $pestName = trim((string) ($pest->pest_name ?: 'pest incidence'));
            $alerts[] = [
                'type' => 'pest',
                'severity' => 'critical',
                'label' => 'Pest Report',
                'message' => "New unverified pest incidence encoded ({$pestName}).",
                'action' => 'Verify & Forward to MAO',
                'route' => '/brgy/pest-monitoring',
            ];
        }

        $pendingDamage = DamageAssessment::query()
            ->where('status', 'Pending')
            ->where(function ($q) use ($barangay) {
                $q->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                    ->orWhereHas('farmPlot', fn ($plot) => $plot->where('location_brgy', $barangay));
            })
            ->get(['calamity_name', 'area_destroyed_ha', 'area_planted_ha', 'damage_percentage']);

        if ($pendingDamage->isNotEmpty()) {
            $ha = $pendingDamage->sum(function (DamageAssessment $row) {
                $destroyed = (float) ($row->area_destroyed_ha ?? 0);
                if ($destroyed > 0) {
                    return $destroyed;
                }

                return ((float) ($row->area_planted_ha ?? 0)) * ((float) ($row->damage_percentage ?? 0) / 100);
            });
            $event = $pendingDamage->pluck('calamity_name')->filter()->first() ?: 'Calamity';
            $haLabel = number_format($ha, 1);

            $alerts[] = [
                'type' => 'calamity',
                'severity' => 'warning',
                'label' => 'Calamity Loss',
                'message' => "{$event} damage report pending ocular inspection ({$haLabel} ha).",
                'action' => 'Track Inspection Status',
                'route' => '/brgy/calamity-assessment',
            ];
        }

        $rainHour = collect($hourlyForecast)->first(
            fn (array $hour) => (int) ($hour['precipitation_probability'] ?? 0) >= 80
        );
        $sprayHour = collect($hourlyForecast)->first(function (array $hour) {
            $rain = (int) ($hour['precipitation_probability'] ?? 0);
            $wind = (float) ($hour['wind_speed'] ?? 0);

            return ($rain >= 70 && $rain < 80) || $wind > 15;
        });

        if ($rainHour) {
            $when = $this->formatHourLabel($rainHour['forecast_datetime'] ?? null);
            $pct = (int) ($rainHour['precipitation_probability'] ?? 0);
            $message = "{$pct}% rain probability at {$when}. Flood watch — delay field work and foliar spray.";
            $alerts[] = [
                'type' => 'weather',
                'severity' => 'warning',
                'label' => 'Weather Advisory',
                'message' => $message,
                'action' => 'Send Brgy SMS Advisory',
                'route' => null,
                'sms_message' => "MAO / Brgy {$barangay} Advisory: {$pct}% rain expected around {$when}. Delay spraying and secure inputs. Stay safe.",
            ];
        } elseif ($sprayHour) {
            $when = $this->formatHourLabel($sprayHour['forecast_datetime'] ?? null);
            $pct = (int) ($sprayHour['precipitation_probability'] ?? 0);
            $wind = (float) ($sprayHour['wind_speed'] ?? 0);
            $reason = $wind > 15
                ? sprintf('Wind %.0f km/h — avoid spray drift.', $wind)
                : "{$pct}% rain — delay scheduled foliar spray.";
            $alerts[] = [
                'type' => 'weather',
                'severity' => 'info',
                'label' => 'Weather Advisory',
                'message' => "{$when}: {$reason}",
                'action' => 'Send Brgy SMS Advisory',
                'route' => null,
                'sms_message' => "Brgy {$barangay} Advisory ({$when}): {$reason}",
            ];
        }

        return $alerts;
    }

    private function formatHourLabel(mixed $iso): string
    {
        if (! is_string($iso) || $iso === '') {
            return 'this window';
        }

        try {
            return Carbon::parse($iso)
                ->timezone(WeatherHourlyService::TIMEZONE)
                ->format('g:i A');
        } catch (\Throwable) {
            return 'this window';
        }
    }

    private function unverifiedPestQuery(string $barangay)
    {
        $query = PestMonitoring::query()
            ->where(function ($q) use ($barangay) {
                $q->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                    ->orWhere('farm_location', $barangay)
                    ->orWhereHas('farmPlot', fn ($plot) => $plot->where('location_brgy', $barangay));
            });

        if (Schema::hasColumn('pest_monitoring', 'status')) {
            $query->where('status', 'Unverified');
        } else {
            $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('photo_path');
            });
        }

        return $query;
    }

    private function transformWeatherCache(WeatherCache $row): array
    {
        $code = $row->weather_code;

        return [
            'id' => $row->id,
            'barangay_name' => $row->barangay_name,
            'forecast_date' => $row->forecast_date->toDateString(),
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

    private function transformHourly(WeatherHourly $row): array
    {
        $code = $row->weather_code;

        return [
            'id' => $row->id,
            'barangay_name' => $row->barangay_name,
            'forecast_datetime' => $row->forecast_datetime
                ->timezone(WeatherHourlyService::TIMEZONE)
                ->toIso8601String(),
            'temperature' => $row->temperature !== null ? (float) $row->temperature : null,
            'precipitation_probability' => $row->precipitation_probability,
            'wind_speed' => $row->wind_speed !== null ? (float) $row->wind_speed : null,
            'weather_code' => $code,
            'status' => $this->statusFromCode($code),
        ];
    }

    private function statusFromCode(?int $code): string
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
}
