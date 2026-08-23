<?php

namespace App\Http\Controllers;

use App\Models\CropMonitoring;
use App\Models\DamageAssessment;
use App\Models\Distribution;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\GeoTag;
use App\Models\PestMonitoring;
use App\Models\PestOutbreak;
use App\Models\PlantingLog;
use App\Models\Program;
use App\Models\ReportWorkflow;
use App\Models\WeatherCache;
use App\Services\ReportAggregationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Unified 4-tier Admin Command Center (real DB aggregates).
     * GET /api/dashboard/overview
     */
    public function overview(): JsonResponse
    {
        $descriptive = $this->overviewDescriptive();
        $diagnostic = $this->overviewDiagnostic();
        $predictive = $this->overviewPredictive();
        $prescriptive = $this->overviewPrescriptive($predictive);

        return response()->json([
            'data' => [
                'descriptive' => $descriptive,
                'diagnostic' => $diagnostic,
                'predictive' => $predictive,
                'prescriptive' => $prescriptive,
            ],
        ]);
    }

    /**
     * Barangay portal KPIs scoped to the official's assigned_barangay.
     * GET /api/dashboard/barangay
     */
    public function barangayOverview(Request $request): JsonResponse
    {
        $user = $request->user();
        $barangay = $user?->assigned_barangay;

        if ($user?->role === 'admin' && $request->filled('barangay')) {
            $barangay = $request->query('barangay');
        }

        if (empty($barangay)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No barangay assignment on this account.',
            ], 403);
        }

        $farmers = Farmer::query()->where('permanent_brgy', $barangay)->count();

        $plantingEntries = 0;
        if (Schema::hasTable('planting_logs')) {
            $plantingEntries = PlantingLog::query()
                ->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay))
                ->count();
        }

        $pestReports = 0;
        if (Schema::hasTable('pest_monitoring')) {
            $pestReports = PestMonitoring::query()
                ->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay))
                ->count();
        }

        $pendingDamage = 0;
        if (Schema::hasTable('damage_assessments')) {
            $pendingDamage = DamageAssessment::query()
                ->where('status', 'Pending')
                ->where(function ($q) use ($barangay) {
                    $q->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay))
                        ->orWhereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $barangay));
                })
                ->count();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'barangay' => $barangay,
                'farmers' => $farmers,
                'planting_entries' => $plantingEntries,
                'pest_reports' => $pestReports,
                'pending_damage' => $pendingDamage,
            ],
        ]);
    }

    private function overviewDescriptive(): array
    {
        $totalFarmers = Farmer::query()->count();
        $totalHectares = $this->overviewActiveHectares();

        $activeSubsidies = 0;
        if (Schema::hasTable('tbl_subsidy_beneficiaries')) {
            $activeSubsidies += DB::table('tbl_subsidy_beneficiaries')
                ->where('status', 'Claimed')
                ->where(function ($q) {
                    $q->where('claimed_at', '>=', Carbon::now()->subDays(90))
                        ->orWhere(function ($inner) {
                            $inner->whereNull('claimed_at')
                                ->where('updated_at', '>=', Carbon::now()->subDays(90));
                        });
                })
                ->count();
        }
        if (Schema::hasTable('distributions')) {
            $activeSubsidies += Distribution::query()
                ->where(function ($q) {
                    $q->where('claimed_at', '>=', Carbon::now()->subDays(90))
                        ->orWhere(function ($inner) {
                            $inner->whereNull('claimed_at')
                                ->where('created_at', '>=', Carbon::now()->subDays(90));
                        });
                })
                ->count();
        }

        $activeCalamitiesPests = 0;
        if (Schema::hasTable('pest_outbreaks')) {
            $activeCalamitiesPests += PestOutbreak::query()
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->count();
        }
        if (Schema::hasTable('pest_monitoring')) {
            $activeCalamitiesPests += PestMonitoring::query()
                ->where(function ($q) {
                    $q->where('is_outbreak', true);
                    if (Schema::hasColumn('pest_monitoring', 'area_damage_pct')) {
                        $q->orWhere('area_damage_pct', '>=', 30);
                    }
                })
                ->count();
        }
        if (Schema::hasTable('damage_assessments')) {
            // Barangay-encoded calamity logs are saved as Verified; technician field
            // reports start as Pending. Both are still active until Claimed/Approved.
            $activeCalamitiesPests += DamageAssessment::query()
                ->whereIn('status', ['Pending', 'Verified'])
                ->count();
        }

        $pendingSubsidyReleases = 0;
        if (Schema::hasTable('tbl_subsidy_beneficiaries')) {
            $pendingSubsidyReleases += DB::table('tbl_subsidy_beneficiaries')
                ->where('status', 'Pending')
                ->count();
        }
        if (Schema::hasTable('distributions')) {
            $pendingSubsidyReleases += Distribution::query()->where('status', 'pending_sync')->count();
        }

        return [
            'total_farmers' => $totalFarmers,
            'total_hectares' => round($totalHectares, 2),
            'active_subsidies' => $activeSubsidies,
            'active_calamities_pests' => $activeCalamitiesPests,
            'pending_subsidy_releases' => $pendingSubsidyReleases,
        ];
    }

    /**
     * Prefer live planting ledgers; fall back to registered farm-plot area.
     */
    private function overviewActiveHectares(): float
    {
        if (Schema::hasTable('planting_logs')) {
            $planted = (float) PlantingLog::query()
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw(
                            "LOWER(status) NOT IN ('not continued', 'harvested', 'completed', 'inactive')"
                        );
                })
                ->sum('area_planted');

            if ($planted > 0) {
                return $planted;
            }
        }

        if (Schema::hasTable('farm_plots')) {
            return (float) FarmPlot::query()->sum('size_ha');
        }

        return 0.0;
    }

    private function overviewDiagnostic(): array
    {
        $pestBreakdown = [];

        if (Schema::hasTable('pest_monitoring')) {
            $hasCropStage = Schema::hasColumn('pest_monitoring', 'crop_stage');
            $hasPestName = Schema::hasColumn('pest_monitoring', 'pest_name');
            $hasSeverity = Schema::hasColumn('pest_monitoring', 'severity');

            $stageExpr = $hasCropStage
                ? "COALESCE(NULLIF(crop_stage, ''), 'Unspecified')"
                : "'Unspecified'";

            $damageExpr = match (true) {
                $hasPestName && $hasSeverity => "COALESCE(NULLIF(pest_name, ''), NULLIF(severity, ''), 'Unknown')",
                $hasPestName => "COALESCE(NULLIF(pest_name, ''), 'Unknown')",
                $hasSeverity => "COALESCE(NULLIF(severity, ''), 'Unknown')",
                default => "'Unknown'",
            };

            // Use positional GROUP BY (1, 2) to satisfy MariaDB ONLY_FULL_GROUP_BY
            // when selecting expression aliases.
            $pestBreakdown = DB::table('pest_monitoring')
                ->selectRaw("{$stageExpr} as crop_stage")
                ->selectRaw("{$damageExpr} as damage_type")
                ->selectRaw('COUNT(*) as total')
                ->groupByRaw('1, 2')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    'crop_stage' => $row->crop_stage,
                    'damage_type' => $row->damage_type,
                    'total' => (int) $row->total,
                ])
                ->values()
                ->all();
        }

        return [
            'pest_breakdown' => $pestBreakdown,
            'crop_distribution' => $this->overviewCropDistribution(),
            'distributions_by_barangay' => $this->overviewDistributionsByBarangay(),
        ];
    }

    /**
     * Crop count/area grouped by commodity for the doughnut chart.
     * Uses live planting logs when present, otherwise registered farm plots.
     */
    private function overviewCropDistribution(): array
    {
        if (Schema::hasTable('planting_logs') && PlantingLog::query()->exists()) {
            return DB::table('planting_logs')
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw(
                            "LOWER(status) NOT IN ('not continued', 'harvested', 'completed', 'inactive')"
                        );
                })
                ->selectRaw("COALESCE(NULLIF(crop_type, ''), 'Other') as commodity")
                ->selectRaw('COUNT(*) as total_plots')
                ->selectRaw('SUM(area_planted) as total_area_ha')
                ->groupByRaw('1')
                ->orderByDesc('total_plots')
                ->get()
                ->map(fn ($row) => [
                    'commodity' => $row->commodity,
                    'total_plots' => (int) $row->total_plots,
                    'total_area_ha' => round((float) $row->total_area_ha, 2),
                ])
                ->values()
                ->all();
        }

        if (! Schema::hasTable('farm_plots')) {
            return [];
        }

        return DB::table('farm_plots')
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(NULLIF(commodity, ''), 'Other') as commodity")
            ->selectRaw('COUNT(*) as total_plots')
            ->selectRaw('SUM(size_ha) as total_area_ha')
            ->groupByRaw('1')
            ->orderByDesc('total_plots')
            ->get()
            ->map(fn ($row) => [
                'commodity' => $row->commodity,
                'total_plots' => (int) $row->total_plots,
                'total_area_ha' => round((float) $row->total_area_ha, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Recent (90-day) subsidy counts by farmer barangay.
     * Combines tbl_subsidy_beneficiaries (current) and distributions (legacy).
     */
    private function overviewDistributionsByBarangay(): array
    {
        if (! Schema::hasTable('farmers')) {
            return [];
        }

        $since = Carbon::now()->subDays(90);
        $rows = collect();

        if (Schema::hasTable('tbl_subsidy_beneficiaries')) {
            $rows = $rows->concat(
                DB::table('tbl_subsidy_beneficiaries')
                    ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                    ->whereNull('farmers.deleted_at')
                    ->where('tbl_subsidy_beneficiaries.status', 'Claimed')
                    ->where(function ($q) use ($since) {
                        $q->where('tbl_subsidy_beneficiaries.claimed_at', '>=', $since)
                            ->orWhere(function ($inner) use ($since) {
                                $inner->whereNull('tbl_subsidy_beneficiaries.claimed_at')
                                    ->where('tbl_subsidy_beneficiaries.updated_at', '>=', $since);
                            });
                    })
                    ->selectRaw("COALESCE(NULLIF(farmers.permanent_brgy, ''), 'Unspecified') as barangay")
                    ->selectRaw('COUNT(*) as total')
                    ->groupByRaw('1')
                    ->get()
            );
        }

        if (Schema::hasTable('distributions')) {
            $rows = $rows->concat(
                DB::table('distributions')
                    ->join('farmers', 'distributions.farmer_id', '=', 'farmers.id')
                    ->whereNull('farmers.deleted_at')
                    ->where('distributions.created_at', '>=', $since)
                    ->selectRaw("COALESCE(NULLIF(farmers.permanent_brgy, ''), 'Unspecified') as barangay")
                    ->selectRaw('COUNT(*) as total')
                    ->groupByRaw('1')
                    ->get()
            );
        }

        return $rows
            ->groupBy('barangay')
            ->map(fn ($group, $barangay) => [
                'barangay' => $barangay,
                'total' => $group->sum(fn ($row) => (int) $row->total),
            ])
            ->sortByDesc('total')
            ->take(8)
            ->values()
            ->all();
    }

    private function overviewPredictive(): array
    {
        $harvestForecast = [];

        if (Schema::hasTable('planting_logs')) {
            $rows = PlantingLog::query()
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw(
                            "LOWER(status) NOT IN ('not continued', 'harvested', 'completed', 'inactive')"
                        );
                })
                ->select('crop_type')
                ->selectRaw('SUM(area_planted) as total_area_ha')
                ->selectRaw('COUNT(*) as field_count')
                ->groupBy('crop_type')
                ->orderByDesc('total_area_ha')
                ->get();

            foreach ($rows as $row) {
                $yieldPerHa = $this->assumedYieldKgPerHa($row->crop_type);
                $area = (float) $row->total_area_ha;
                $harvestForecast[] = [
                    'crop_type' => $row->crop_type ?: 'Unknown',
                    'total_area_ha' => round($area, 2),
                    'field_count' => (int) $row->field_count,
                    'yield_kg_per_ha' => $yieldPerHa,
                    'estimated_harvest_kg' => round($area * $yieldPerHa, 2),
                ];
            }
        }

        $weatherRisk = [];
        if (Schema::hasTable('tbl_weather_cache')) {
            $etColumn = Schema::hasColumn('tbl_weather_cache', 'evapotranspiration')
                ? 'evapotranspiration'
                : (Schema::hasColumn('tbl_weather_cache', 'et0_fao_evapotranspiration')
                    ? 'et0_fao_evapotranspiration'
                    : null);

            $query = WeatherCache::query()
                ->whereDate('forecast_date', '>=', Carbon::today())
                ->whereDate('forecast_date', '<=', Carbon::today()->addDays(3))
                ->where(function ($q) use ($etColumn) {
                    $q->where('precipitation_probability', '>', 80);
                    if ($etColumn) {
                        $q->orWhere($etColumn, '>', 5);
                    }
                });

            $select = [
                'barangay_name',
                DB::raw('MAX(precipitation_probability) as max_precip'),
            ];
            if ($etColumn) {
                $select[] = DB::raw("MAX({$etColumn}) as max_et0");
            }

            $weatherRows = $query
                ->select($select)
                ->groupBy('barangay_name')
                ->orderByDesc('max_precip')
                ->get();

            foreach ($weatherRows as $w) {
                $precip = (int) ($w->max_precip ?? 0);
                $et0 = (float) ($w->max_et0 ?? 0);
                $risks = [];
                if ($precip > 80) {
                    $risks[] = 'Flood Risk';
                }
                if ($etColumn && $et0 > 5) {
                    $risks[] = 'Drought Risk';
                }
                if (! $risks) {
                    continue;
                }

                $weatherRisk[] = [
                    'barangay' => $w->barangay_name,
                    'precipitation_probability' => $precip,
                    'et0' => $etColumn ? round($et0, 3) : null,
                    'risks' => $risks,
                    'primary_risk' => $precip > 80 ? 'Flood Risk' : 'Drought Risk',
                ];
            }
        }

        return [
            'harvest_forecast' => $harvestForecast,
            'weather_risk' => $weatherRisk,
        ];
    }

    /**
     * @param  array{harvest_forecast: array<int, mixed>, weather_risk: array<int, mixed>}  $predictive
     */
    private function overviewPrescriptive(array $predictive): array
    {
        $alerts = $this->overviewPestAlerts();

        foreach ($predictive['weather_risk'] as $risk) {
            $barangay = $risk['barangay'];
            $risks = $risk['risks'] ?? [];

            if (in_array('Flood Risk', $risks, true)) {
                $alerts[] = [
                    'type' => 'weather_alert',
                    'barangay' => $barangay,
                    'message' => "High Flood Risk in {$barangay}. Recommend buffer seed allocation and SMS warning.",
                ];
            }

            if (in_array('Drought Risk', $risks, true)) {
                $alerts[] = [
                    'type' => 'weather_alert',
                    'barangay' => $barangay,
                    'message' => "High Drought Risk in {$barangay} (elevated ET0). Recommend irrigation advisory and SMS warning.",
                ];
            }
        }

        foreach ($predictive['harvest_forecast'] as $crop) {
            if (($crop['total_area_ha'] ?? 0) >= 50) {
                $alerts[] = [
                    'type' => 'harvest_readiness',
                    'barangay' => null,
                    'message' => sprintf(
                        'Large active %s area (%.1f ha). Recommend staging post-harvest logistics for ~%s kg projected yield.',
                        $crop['crop_type'],
                        $crop['total_area_ha'],
                        number_format($crop['estimated_harvest_kg'])
                    ),
                ];
            }
        }

        return [
            'alerts' => $alerts,
        ];
    }

    /**
     * Critical, actionable alerts for currently Active pest outbreaks.
     * Kept intentionally short (top 5, most recent) to avoid noise.
     */
    private function overviewPestAlerts(): array
    {
        $alerts = [];

        if (Schema::hasTable('pest_outbreaks')) {
            $alerts = PestOutbreak::query()
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->with('farmPlot:id,location_brgy,commodity')
                ->orderByDesc('date_spotted')
                ->limit(5)
                ->get()
                ->map(function ($p) {
                    $brgy = optional($p->farmPlot)->location_brgy;
                    $commodity = optional($p->farmPlot)->commodity;

                    return [
                        'type' => 'pest_outbreak',
                        'barangay' => $brgy,
                        'message' => sprintf(
                            'Active %s outbreak (%s severity) in %s%s. Recommend field validation and targeted spray advisory.',
                            $p->pest_name ?: 'pest',
                            $p->severity ?: 'unspecified',
                            $brgy ?: 'an unlisted barangay',
                            $commodity ? " ({$commodity})" : ''
                        ),
                    ];
                })
                ->values()
                ->all();
        }

        if (count($alerts) >= 5 || ! Schema::hasTable('pest_monitoring')) {
            return $alerts;
        }

        $remaining = 5 - count($alerts);
        $monitoringQuery = PestMonitoring::query()
            ->with([
                'farmer:id,permanent_brgy',
                'farmPlot:id,location_brgy,commodity',
            ])
            ->where(function ($q) {
                $q->where('is_outbreak', true);
                if (Schema::hasColumn('pest_monitoring', 'area_damage_pct')) {
                    $q->orWhere('area_damage_pct', '>=', 30);
                }
            });

        if (Schema::hasColumn('pest_monitoring', 'date_of_inspection')) {
            $monitoringQuery->orderByDesc('date_of_inspection');
        }

        $monitoring = $monitoringQuery
            ->orderByDesc('created_at')
            ->limit($remaining)
            ->get()
            ->map(function ($p) {
                $brgy = optional($p->farmPlot)->location_brgy
                    ?? optional($p->farmer)->permanent_brgy
                    ?? $p->farm_location;

                return [
                    'type' => 'pest_outbreak',
                    'barangay' => $brgy,
                    'message' => sprintf(
                        'Encoded %s report (%s severity) in %s%s. Recommend field validation and targeted spray advisory.',
                        $p->pest_name ?: 'pest',
                        $p->severity ?: 'unspecified',
                        $brgy ?: 'an unlisted barangay',
                        $p->crop ? " ({$p->crop})" : ''
                    ),
                ];
            })
            ->all();

        return array_values(array_merge($alerts, $monitoring));
    }

    private function assumedYieldKgPerHa(?string $cropType): float
    {
        $crop = strtolower(trim((string) $cropType));

        return match (true) {
            str_contains($crop, 'rice') => 4500.0,
            str_contains($crop, 'corn') => 5000.0,
            str_contains($crop, 'high') => 3500.0,
            default => 4000.0,
        };
    }

    /**
     * Geospatial payload for the GIS map view.
     * Returns geotagged farm plots, damage points, and pest outbreaks.
     * Optional filters: ?barangay=&commodity=&layer=(farms|damage|pests)
     */
    public function mapData(Request $request): JsonResponse
    {
        $barangay = $request->query('barangay');
        $commodity = $request->query('commodity');
        $layer = $request->query('layer'); // null => all layers

        $wantFarms = !$layer || $layer === 'farms';
        $wantDamage = !$layer || $layer === 'damage';
        $wantPests = !$layer || $layer === 'pests';

        $farmPlots = [];
        if ($wantFarms) {
            $farmPlots = FarmPlot::with('farmer:id,first_name,surname')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->when($barangay, fn ($q) => $q->where('location_brgy', $barangay))
                ->when($commodity, fn ($q) => $q->where('commodity', $commodity))
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'lat' => (float) $p->latitude,
                    'lng' => (float) $p->longitude,
                    'commodity' => $p->commodity,
                    'size_ha' => $p->size_ha !== null ? (float) $p->size_ha : null,
                    'brgy' => $p->location_brgy,
                    'farmer_name' => trim((optional($p->farmer)->first_name ?? '') . ' ' . (optional($p->farmer)->surname ?? '')),
                ])
                ->values();
        }

        $damagePoints = [];
        if ($wantDamage) {
            $damagePoints = DamageAssessment::with([
                'farmer:id,first_name,surname,permanent_brgy',
                'farmPlot:id,commodity,location_brgy,latitude,longitude',
            ])
                ->where(function ($q) {
                    $q->where(function ($geo) {
                        $geo->whereNotNull('latitude')->whereNotNull('longitude');
                    })->orWhereHas('farmPlot', fn ($fp) => $fp->whereNotNull('latitude')->whereNotNull('longitude'));
                })
                ->when($barangay, function ($q) use ($barangay) {
                    $q->where(function ($sub) use ($barangay) {
                        $sub->whereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $barangay))
                            ->orWhereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay));
                    });
                })
                ->when($commodity, fn ($q) => $q->whereHas('farmPlot', fn ($fp) => $fp->where('commodity', $commodity)))
                ->get()
                ->map(function ($a) {
                    $lat = $a->latitude ?? optional($a->farmPlot)->latitude;
                    $lng = $a->longitude ?? optional($a->farmPlot)->longitude;
                    if ($lat === null || $lng === null) {
                        return null;
                    }

                    return [
                        'id' => $a->id,
                        'lat' => (float) $lat,
                        'lng' => (float) $lng,
                        'damage_percentage' => (float) $a->damage_percentage,
                        'calamity_name' => $a->calamity_name,
                        'status' => $a->status,
                        'commodity' => optional($a->farmPlot)->commodity,
                        'brgy' => optional($a->farmPlot)->location_brgy ?? optional($a->farmer)->permanent_brgy,
                        'farmer_name' => trim((optional($a->farmer)->first_name ?? '') . ' ' . (optional($a->farmer)->surname ?? '')),
                    ];
                })
                ->filter()
                ->values();
        }

        $pestOutbreaks = collect();
        if ($wantPests) {
            $pestOutbreaks = $pestOutbreaks->concat(
                PestOutbreak::with([
                    'farmPlot:id,commodity,location_brgy,latitude,longitude',
                ])
                    ->where(function ($q) {
                        $q->where(function ($geo) {
                            $geo->whereNotNull('latitude')->whereNotNull('longitude');
                        })->orWhereHas('farmPlot', fn ($fp) => $fp->whereNotNull('latitude')->whereNotNull('longitude'));
                    })
                    ->when($barangay, fn ($q) => $q->whereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $barangay)))
                    ->when($commodity, fn ($q) => $q->whereHas('farmPlot', fn ($fp) => $fp->where('commodity', $commodity)))
                    ->get()
                    ->map(function ($p) {
                        $lat = $p->latitude ?? optional($p->farmPlot)->latitude;
                        $lng = $p->longitude ?? optional($p->farmPlot)->longitude;
                        if ($lat === null || $lng === null) {
                            return null;
                        }

                        return [
                            'id' => $p->id,
                            'lat' => (float) $lat,
                            'lng' => (float) $lng,
                            'pest_name' => $p->pest_name,
                            'severity' => $p->severity,
                            'status' => $p->status ?: 'Active',
                            'commodity' => optional($p->farmPlot)->commodity,
                            'brgy' => optional($p->farmPlot)->location_brgy,
                        ];
                    })
                    ->filter()
                    ->values()
            );

            if (Schema::hasTable('pest_monitoring')) {
                $hasDamagePct = Schema::hasColumn('pest_monitoring', 'area_damage_pct');
                $pestOutbreaks = $pestOutbreaks->concat(
                    PestMonitoring::with([
                        'farmer:id,permanent_brgy',
                        'farmPlot:id,commodity,location_brgy,latitude,longitude',
                    ])
                        ->where(function ($q) {
                            $q->where(function ($geo) {
                                $geo->whereNotNull('latitude')->whereNotNull('longitude');
                            })->orWhereHas('farmPlot', fn ($fp) => $fp->whereNotNull('latitude')->whereNotNull('longitude'));
                        })
                        ->when($barangay, function ($q) use ($barangay) {
                            $q->where(function ($sub) use ($barangay) {
                                $sub->whereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $barangay))
                                    ->orWhereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay));
                            });
                        })
                        ->when($commodity, function ($q) use ($commodity) {
                            $q->where(function ($sub) use ($commodity) {
                                $sub->where('crop', $commodity)
                                    ->orWhereHas('farmPlot', fn ($fp) => $fp->where('commodity', $commodity));
                            });
                        })
                        ->get()
                        ->map(function ($p) use ($hasDamagePct) {
                            $lat = $p->latitude ?? optional($p->farmPlot)->latitude;
                            $lng = $p->longitude ?? optional($p->farmPlot)->longitude;
                            if ($lat === null || $lng === null) {
                                return null;
                            }

                            $isActive = (bool) $p->is_outbreak
                                || ($hasDamagePct && (float) $p->area_damage_pct >= 30);

                            return [
                                'id' => $p->id,
                                'lat' => (float) $lat,
                                'lng' => (float) $lng,
                                'pest_name' => $p->pest_name,
                                'severity' => $p->severity,
                                'status' => $isActive ? 'Active' : 'Reported',
                                'commodity' => $p->crop ?? optional($p->farmPlot)->commodity,
                                'brgy' => optional($p->farmPlot)->location_brgy
                                    ?? optional($p->farmer)->permanent_brgy
                                    ?? $p->farm_location,
                            ];
                        })
                        ->filter()
                        ->values()
                );
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'farm_plots' => $farmPlots,
                'damage_points' => $damagePoints,
                'pest_outbreaks' => $pestOutbreaks->values(),
            ],
        ]);
    }

    /**
     * Fetch high-level KPIs, recent audit trail, and damage summary
     * for the admin Mission Control dashboard.
     */
    public function getStats(): JsonResponse
    {
        $activeProgramsCount = Program::where('is_active', true)
            ->where('end_date', '>=', now())
            ->count();

        $totalFarmers = Farmer::count();

        $dispensedTotals = Distribution::join('programs', 'distributions.program_id', '=', 'programs.id')
            ->select(
                'programs.unit_of_measurement as unit',
                DB::raw('SUM(distributions.quantity_claimed) as total_dispensed')
            )
            ->where('distributions.status', 'claimed')
            ->groupBy('programs.unit_of_measurement')
            ->get();

        $damageSummary = [
            'total' => DamageAssessment::count(),
            'pending' => DamageAssessment::where('status', 'Pending')->count(),
            'verified' => DamageAssessment::where('status', 'Verified')->count(),
            'approved' => DamageAssessment::where('status', 'Approved')->count(),
        ];

        $activePests = PestOutbreak::where('status', 'Active')->count();

        $pendingReports = ReportWorkflow::where('provincial_status', 'Pending')->count();

        $recentTransactions = Distribution::with([
            'farmer:id,first_name,surname,permanent_brgy',
            'program:id,name,unit_of_measurement',
            'technician:id,name',
        ])
            ->latest()
            ->take(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'date' => $t->created_at->format('M d, Y h:i A'),
                'farmer_name' => optional($t->farmer)->first_name . ' ' . optional($t->farmer)->surname,
                'barangay' => optional($t->farmer)->permanent_brgy,
                'program_name' => optional($t->program)->name,
                'dispensed' => $t->quantity_claimed . ' ' . optional($t->program)->unit_of_measurement,
                'technician' => optional($t->technician)->name,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'metrics' => [
                    'active_programs' => $activeProgramsCount,
                    'total_farmers' => $totalFarmers,
                    'dispensed_breakdown' => $dispensedTotals,
                    'damage_summary' => $damageSummary,
                    'active_pests' => $activePests,
                    'pending_reports' => $pendingReports,
                ],
                'audit_trail' => $recentTransactions,
            ],
        ], 200);
    }

    /**
     * Generate data for the Accomplishment Report (Phase 5 - Executive Reporting).
     * Delegates to ReportAggregationService for filter-aware aggregation.
     */
    public function accomplishmentReport(ReportAggregationService $aggregation): JsonResponse
    {
        $payload = $aggregation->aggregate([
            'report_type' => 'Provincial Accomplishment Report',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $payload,
        ]);
    }

    /**
     * Personal contribution history for the active reporting period (technician).
     */
    public function activityLog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->format('Y-m-d');
        $dateTo = $validated['date_to'] ?? now()->format('Y-m-d');
        $techId = $request->user()->id;

        $distributions = Distribution::with([
            'farmer:id,first_name,surname,permanent_brgy',
            'program:id,name,unit_of_measurement',
        ])
            ->where('distributed_by', $techId)
            ->where('status', 'claimed')
            ->whereDate('claimed_at', '>=', $dateFrom)
            ->whereDate('claimed_at', '<=', $dateTo)
            ->orderByDesc('claimed_at')
            ->limit(25)
            ->get()
            ->map(fn ($d) => [
                'type' => 'distribution',
                'id' => $d->id,
                'date' => optional($d->claimed_at)->format('M d, Y h:i A'),
                'label' => optional($d->program)->name,
                'detail' => trim((optional($d->farmer)->first_name ?? '') . ' ' . (optional($d->farmer)->surname ?? '')),
                'barangay' => optional($d->farmer)->permanent_brgy,
                'quantity' => $d->quantity_claimed . ' ' . optional($d->program)->unit_of_measurement,
                'status' => $d->status,
            ]);

        $assessments = DamageAssessment::with([
            'farmer:id,first_name,surname,permanent_brgy',
            'farmPlot:id,commodity',
        ])
            ->where('technician_id', $techId)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn ($a) => [
                'type' => 'damage_assessment',
                'id' => $a->id,
                'date' => $a->created_at->format('M d, Y h:i A'),
                'label' => $a->calamity_name,
                'detail' => trim((optional($a->farmer)->first_name ?? '') . ' ' . (optional($a->farmer)->surname ?? '')),
                'barangay' => optional($a->farmer)->permanent_brgy,
                'quantity' => $a->damage_percentage . '% damage',
                'status' => $a->status,
            ]);

        $pests = PestOutbreak::with(['farmPlot:id,location_brgy,commodity'])
            ->where('technician_id', $techId)
            ->whereDate('date_spotted', '>=', $dateFrom)
            ->whereDate('date_spotted', '<=', $dateTo)
            ->orderByDesc('date_spotted')
            ->limit(25)
            ->get()
            ->map(fn ($p) => [
                'type' => 'pest_outbreak',
                'id' => $p->id,
                'date' => optional($p->date_spotted)->format('M d, Y'),
                'label' => $p->pest_name,
                'detail' => optional($p->farmPlot)->commodity,
                'barangay' => optional($p->farmPlot)->location_brgy,
                'quantity' => $p->severity,
                'status' => $p->status,
            ]);

        $distCount = Distribution::where('distributed_by', $techId)
            ->where('status', 'claimed')
            ->whereDate('claimed_at', '>=', $dateFrom)
            ->whereDate('claimed_at', '<=', $dateTo)
            ->count();

        $assessCount = DamageAssessment::where('technician_id', $techId)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->count();

        $pestCount = PestOutbreak::where('technician_id', $techId)
            ->whereDate('date_spotted', '>=', $dateFrom)
            ->whereDate('date_spotted', '<=', $dateTo)
            ->count();

        $recent = $distributions
            ->concat($assessments)
            ->concat($pests)
            ->sortByDesc('date')
            ->take(30)
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'summary' => [
                    'distributions' => $distCount,
                    'damage_assessments' => $assessCount,
                    'pest_outbreaks' => $pestCount,
                ],
                'recent' => $recent,
            ],
        ]);
    }

    /**
     * Combined recent field work for the technician History tab.
     */
    public function fieldHistory(Request $request): JsonResponse
    {
        $techId = $request->user()->id;
        $items = collect();

        GeoTag::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (GeoTag $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Geo-Tag',
                    'title' => $farmer !== '' ? $farmer : ($row->crop_planted ?: 'Mapped parcel'),
                    'detail' => trim(($row->crop_planted ?: 'Parcel').($row->crop_variety ? ' · '.$row->crop_variety : '')),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        PlantingLog::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (PlantingLog $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Planting',
                    'title' => $farmer !== '' ? $farmer : ($row->crop_type ?: 'Planting log'),
                    'detail' => trim(($row->crop_type ?: 'Crop').($row->variety ? ' · '.$row->variety : '')),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        PestMonitoring::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (PestMonitoring $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Pest',
                    'title' => $farmer !== '' ? $farmer : ($row->pest_name ?: 'Pest report'),
                    'detail' => trim(($row->pest_name ?: $row->crop ?: 'Pest').($row->severity ? ' · '.$row->severity : '')),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        DamageAssessment::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (DamageAssessment $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Calamity',
                    'title' => $farmer !== '' ? $farmer : ($row->calamity_name ?: 'Damage report'),
                    'detail' => trim(($row->calamity_name ?: $row->calamity_type ?: 'Calamity').($row->status ? ' · '.$row->status : '')),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        Distribution::with(['farmer:id,first_name,surname', 'program:id,name'])
            ->where('distributed_by', $techId)
            ->orderByDesc('claimed_at')
            ->limit(10)
            ->get()
            ->each(function (Distribution $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Subsidy',
                    'title' => $farmer !== '' ? $farmer : ($row->item_released ?: 'Subsidy release'),
                    'detail' => trim(($row->program?->name ?: $row->item_released ?: 'Subsidy').' · '.($row->quantity_claimed ?? '')),
                    'created_at' => optional($row->claimed_at ?? $row->created_at)?->toIso8601String(),
                ]);
            });

        if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'claimed_by')) {
            DB::table('tbl_subsidy_beneficiaries')
                ->leftJoin('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                ->leftJoin('tbl_subsidy_programs', 'tbl_subsidy_programs.id', '=', 'tbl_subsidy_beneficiaries.program_id')
                ->where('tbl_subsidy_beneficiaries.claimed_by', $techId)
                ->orderByDesc('tbl_subsidy_beneficiaries.claimed_at')
                ->limit(10)
                ->get([
                    'tbl_subsidy_beneficiaries.id',
                    'tbl_subsidy_beneficiaries.claimed_at',
                    'tbl_subsidy_beneficiaries.calculated_allocation',
                    'farmers.surname',
                    'farmers.first_name',
                    'tbl_subsidy_programs.program_name',
                    'tbl_subsidy_programs.unit_of_measurement',
                ])
                ->each(function ($row) use ($items) {
                    $farmer = trim((string) ($row->surname ?? '').', '.($row->first_name ?? ''), ' ,');
                    $items->push([
                        'type' => 'Subsidy',
                        'title' => $farmer !== '' ? $farmer : ($row->program_name ?: 'Subsidy release'),
                        'detail' => trim(($row->program_name ?: 'Subsidy').' · '.($row->calculated_allocation ?? '').' '.($row->unit_of_measurement ?? '')),
                        'created_at' => $row->claimed_at
                            ? Carbon::parse($row->claimed_at)->toIso8601String()
                            : null,
                    ]);
                });
        }

        $data = $items
            ->filter(fn ($row) => ! empty($row['created_at']))
            ->sortByDesc('created_at')
            ->values()
            ->take(30)
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Predictive analytics: crop-yield trend and next-season projection.
     * Uses a transparent least-squares linear trend over historical yield
     * per commodity. Falls back to expected yield when actual is missing.
     * Optional filter: ?commodity=
     */
    public function forecast(Request $request): JsonResponse
    {
        $commodityFilter = $request->query('commodity');

        $rows = CropMonitoring::query()
            ->when($commodityFilter, fn ($q) => $q->where('crop_planted', $commodityFilter))
            ->selectRaw(
                'crop_planted, year, ' .
                'SUM(COALESCE(actual_yield_kg, expected_yield_kg, 0)) as total_yield, ' .
                'SUM(COALESCE(area_planted_ha, 0)) as total_area, ' .
                'SUM(CASE WHEN actual_yield_kg IS NOT NULL THEN 1 ELSE 0 END) as yield_records, ' .
                'COUNT(*) as records'
            )
            ->groupBy('crop_planted', 'year')
            ->orderBy('crop_planted')
            ->orderBy('year')
            ->get();

        $commodities = [];
        foreach ($rows->groupBy('crop_planted') as $commodity => $series) {
            $history = $series
                ->map(function ($r) {
                    $ty = (float) $r->total_yield;
                    $ta = (float) $r->total_area;
                    return [
                        'year' => (int) $r->year,
                        'total_yield_kg' => round($ty, 2),
                        'total_area_ha' => round($ta, 2),
                        'yield_per_ha' => $ta > 0 ? round($ty / $ta, 2) : null,
                        'records' => (int) $r->records,
                        'yield_records' => (int) $r->yield_records,
                    ];
                })
                ->values();

            $points = $history
                ->filter(fn ($h) => $h['total_yield_kg'] > 0)
                ->map(fn ($h) => [(float) $h['year'], (float) $h['total_yield_kg']])
                ->values()
                ->all();

            $forecast = null;
            if (count($points) >= 2) {
                $reg = $this->linearRegression($points);
                if ($reg) {
                    $nextYear = (int) $history->max('year') + 1;
                    $projected = $reg['intercept'] + $reg['slope'] * $nextYear;
                    $projected = max(0, $projected);
                    $r2 = $reg['r2'];
                    $forecast = [
                        'year' => $nextYear,
                        'projected_yield_kg' => round($projected, 2),
                        'trend' => $reg['slope'] > 0 ? 'increasing' : ($reg['slope'] < 0 ? 'decreasing' : 'flat'),
                        'confidence' => $r2 >= 0.75 ? 'High' : ($r2 >= 0.4 ? 'Moderate' : 'Low'),
                        'r_squared' => round($r2, 3),
                        'method' => 'Least-squares linear trend',
                    ];
                }
            }

            $commodities[] = [
                'commodity' => $commodity,
                'history' => $history,
                'forecast' => $forecast,
                'note' => count($points) < 2
                    ? 'Insufficient yield history (need at least two years of data).'
                    : null,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'generated_at' => now()->format('F j, Y g:i A'),
                'commodities' => $commodities,
            ],
        ]);
    }

    /**
     * Agricultural risk index (0-100) per barangay + commodity, derived from
     * historical damage severity/frequency and active pest density.
     */
    public function riskIndex(Request $request): JsonResponse
    {
        $barangay = $request->query('barangay');
        $commodity = $request->query('commodity');

        $damage = DamageAssessment::query()
            ->join('farm_plots', 'damage_assessments.farm_plot_id', '=', 'farm_plots.id')
            ->when($barangay, fn ($q) => $q->where('farm_plots.location_brgy', $barangay))
            ->when($commodity, fn ($q) => $q->where('farm_plots.commodity', $commodity))
            ->selectRaw(
                'farm_plots.location_brgy as brgy, farm_plots.commodity as commodity, ' .
                'COUNT(*) as events, AVG(damage_assessments.damage_percentage) as avg_damage'
            )
            ->groupBy('farm_plots.location_brgy', 'farm_plots.commodity')
            ->get();

        $pests = PestOutbreak::query()
            ->join('farm_plots', 'pest_outbreaks.farm_plot_id', '=', 'farm_plots.id')
            ->where('pest_outbreaks.status', 'Active')
            ->when($barangay, fn ($q) => $q->where('farm_plots.location_brgy', $barangay))
            ->when($commodity, fn ($q) => $q->where('farm_plots.commodity', $commodity))
            ->selectRaw('farm_plots.location_brgy as brgy, farm_plots.commodity as commodity, COUNT(*) as active_pests')
            ->groupBy('farm_plots.location_brgy', 'farm_plots.commodity')
            ->get();

        $map = [];
        $keyFor = fn ($b, $c) => ($b ?? 'Unknown') . '||' . ($c ?? 'Unknown');

        foreach ($damage as $d) {
            $k = $keyFor($d->brgy, $d->commodity);
            $map[$k] = [
                'barangay' => $d->brgy ?? 'Unknown',
                'commodity' => $d->commodity ?? 'Unknown',
                'damage_events' => (int) $d->events,
                'avg_damage' => round((float) $d->avg_damage, 1),
                'active_pests' => 0,
            ];
        }
        foreach ($pests as $p) {
            $k = $keyFor($p->brgy, $p->commodity);
            if (!isset($map[$k])) {
                $map[$k] = [
                    'barangay' => $p->brgy ?? 'Unknown',
                    'commodity' => $p->commodity ?? 'Unknown',
                    'damage_events' => 0,
                    'avg_damage' => 0.0,
                    'active_pests' => 0,
                ];
            }
            $map[$k]['active_pests'] = (int) $p->active_pests;
        }

        $items = collect($map)->map(function ($row) {
            $damageScore = min($row['avg_damage'], 100);            // 0-100
            $pestScore = min($row['active_pests'] * 20, 100);        // each active outbreak +20
            $score = (int) round(0.6 * $damageScore + 0.4 * $pestScore);
            $level = $score >= 66 ? 'High' : ($score >= 33 ? 'Moderate' : 'Low');
            return array_merge($row, [
                'risk_score' => $score,
                'risk_level' => $level,
            ]);
        })
            ->sortByDesc('risk_score')
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'generated_at' => now()->format('F j, Y g:i A'),
                'items' => $items,
                'formula' => 'risk = 0.6 * avg_damage% + 0.4 * min(active_pests * 20, 100)',
            ],
        ]);
    }

    /**
     * Ordinary least-squares linear regression.
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array{slope: float, intercept: float, r2: float}|null
     */
    private function linearRegression(array $points): ?array
    {
        $n = count($points);
        if ($n < 2) {
            return null;
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) {
            return null;
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        // R-squared
        $meanY = $sumY / $n;
        $ssTot = $ssRes = 0.0;
        foreach ($points as [$x, $y]) {
            $pred = $intercept + $slope * $x;
            $ssRes += ($y - $pred) ** 2;
            $ssTot += ($y - $meanY) ** 2;
        }
        $r2 = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 1.0;

        return ['slope' => $slope, 'intercept' => $intercept, 'r2' => $r2];
    }
}
