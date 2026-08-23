<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\HarvestLog;
use App\Models\PestMonitoring;
use App\Models\StandingCropLog;
use App\Models\SubsidyBeneficiary;
use App\Models\WeatherCache;
use App\Models\WeatherHourly;
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

        $hourlyForecast = $this->hourlyForecast($barangay);

        return response()->json([
            'data' => [
                'descriptive' => $this->descriptive($barangay),
                'diagnostic' => [
                    'current_weather' => $this->currentWeather($barangay),
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

        $hectares = $this->plotHectares($barangay);
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
            'total_hectares' => round($hectares['rice'] + $hectares['corn'] + $hectares['other'], 2),
            'rice_hectares' => round($hectares['rice'], 2),
            'corn_hectares' => round($hectares['corn'], 2),
            'active_hectares' => round($hectares['rice'] + $hectares['corn'] + $hectares['other'], 2),
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
     * Active crop lifecycle mix from standing crops (fallback: pest inspections).
     *
     * @return array{seedling: int, vegetative: int, reproductive: int, maturity: int}
     */
    private function cropStages(string $barangay): array
    {
        $stages = [
            'seedling' => 0,
            'vegetative' => 0,
            'reproductive' => 0,
            'maturity' => 0,
        ];

        $tally = function (string $raw) use (&$stages): void {
            $key = match (true) {
                str_contains($raw, 'seed') => 'seedling',
                str_contains($raw, 'veget') => 'vegetative',
                str_contains($raw, 'reprod') => 'reproductive',
                str_contains($raw, 'matur') => 'maturity',
                default => null,
            };
            if ($key) {
                $stages[$key]++;
            }
        };

        if (Schema::hasTable('standing_crop_logs')) {
            StandingCropLog::query()
                ->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                ->pluck('growth_stage')
                ->each(fn ($stage) => $tally(strtolower((string) $stage)));
        }

        if (array_sum($stages) === 0 && Schema::hasColumn('pest_monitoring', 'crop_stage')) {
            PestMonitoring::query()
                ->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                ->pluck('crop_stage')
                ->each(fn ($stage) => $tally(strtolower((string) $stage)));
        }

        return $stages;
    }

    /**
     * Last 6 months of harvest yield vs crop damage (hectares affected).
     *
     * @return array<int, array{month: string, key: string, harvest: float, damage: float}>
     */
    private function monthlyYieldDamage(string $barangay): array
    {
        $start = Carbon::now(WeatherService::TIMEZONE)->startOfMonth()->subMonths(5);
        $buckets = [];

        for ($i = 0; $i < 6; $i++) {
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
                ->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay))
                ->whereDate('date_harvested', '>=', $start->toDateString())
                ->get(['date_harvested', 'total_yield'])
                ->each(function (HarvestLog $row) use (&$buckets) {
                    $key = optional($row->date_harvested)?->format('Y-m');
                    if ($key && isset($buckets[$key])) {
                        $buckets[$key]['harvest'] += (float) $row->total_yield;
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
            'damage' => round($row['damage'], 2),
        ], $buckets));
    }

    /**
     * Latest cached daily weather row for this barangay (today, else most recent).
     */
    private function currentWeather(string $barangay): ?array
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

        return $row ? $this->transformWeatherCache($row) : null;
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
     * Action Center alerts derived from the local forecast and unverified pest reports.
     *
     * @param  array<int, array<string, mixed>>  $hourlyForecast
     * @return array<int, array{type: string, message: string, action: string}>
     */
    private function prescriptiveAlerts(string $barangay, array $hourlyForecast): array
    {
        $alerts = [];

        $heavyRain = collect($hourlyForecast)->contains(
            fn (array $hour) => (int) ($hour['precipitation_probability'] ?? 0) > 70
        );

        if ($heavyRain) {
            $alerts[] = [
                'type' => 'weather',
                'message' => 'Heavy rain expected. Delay fertilizer application.',
                'action' => 'Draft SMS',
            ];
        }

        if ($this->hasUnverifiedPestReports($barangay)) {
            $alerts[] = [
                'type' => 'pest',
                'message' => 'New unverified pest report.',
                'action' => 'Review Report',
            ];
        }

        return $alerts;
    }

    /**
     * Pest rows for this barangay that still need review.
     * Uses status = Unverified when the column exists; otherwise pending field validation.
     */
    private function hasUnverifiedPestReports(string $barangay): bool
    {
        return $this->unverifiedPestQuery($barangay)->exists();
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
