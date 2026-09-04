<?php

namespace App\Http\Controllers;

use App\Models\CropMonitoring;
use App\Models\DamageAssessment;
use App\Models\Distribution;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\GeoTag;
use App\Models\GeoTagRefusal;
use App\Models\HarvestLog;
use App\Models\PestMonitoring;
use App\Models\PestOutbreak;
use App\Models\PlantingLog;
use App\Models\Program;
use App\Models\ReportWorkflow;
use App\Models\StandingCropLog;
use App\Models\SubsidyBeneficiary;
use App\Models\WeatherCache;
use App\Services\CropStageService;
use App\Services\ReportAggregationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        if ($user?->isMunicipalAdmin() && $request->filled('barangay')) {
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
        $gender = $this->overviewFarmerGender();
        $hectares = $this->overviewPlotHectares();
        $planted = $this->overviewActivePlantedHectares();
        $totalHectares = $hectares['rice'] + $hectares['corn'] + $hectares['other'];
        $totalPlanted = $planted['rice'] + $planted['corn'] + $planted['other'];
        $subsidy = $this->overviewSubsidyProgress();
        $threats = $this->overviewThreatIndex();

        $activeSubsidies = 0;
        if (Schema::hasTable('tbl_subsidy_beneficiaries')) {
            $activeQuery = DB::table('tbl_subsidy_beneficiaries')
                ->where('status', 'Claimed')
                ->where(function ($q) {
                    $q->where('claimed_at', '>=', Carbon::now()->subDays(90))
                        ->orWhere(function ($inner) {
                            $inner->whereNull('claimed_at')
                                ->where('updated_at', '>=', Carbon::now()->subDays(90));
                        });
                });
            SubsidyBeneficiary::applyNotDeleted($activeQuery);
            $activeSubsidies += $activeQuery->count();
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

        $pendingSubsidyReleases = 0;
        if (Schema::hasTable('tbl_subsidy_beneficiaries')) {
            $pendingQuery = DB::table('tbl_subsidy_beneficiaries')
                ->where('status', 'Pending');
            SubsidyBeneficiary::applyNotDeleted($pendingQuery);
            $pendingSubsidyReleases += $pendingQuery->count();
        }
        if (Schema::hasTable('distributions')) {
            $pendingSubsidyReleases += Distribution::query()->where('status', 'pending_sync')->count();
        }

        return [
            'total_farmers' => $totalFarmers,
            'farmers_male' => $gender['male'],
            'farmers_female' => $gender['female'],
            'rsbsa_verified' => $gender['rsbsa_verified'],
            'total_hectares' => round($totalHectares, 2),
            'rice_hectares' => round($hectares['rice'], 2),
            'corn_hectares' => round($hectares['corn'], 2),
            'active_planted_ha' => round($totalPlanted, 2),
            'active_rice_ha' => round($planted['rice'], 2),
            'active_corn_ha' => round($planted['corn'], 2),
            'registered_land_ha' => round($totalHectares, 2),
            'registered_rice_ha' => round($hectares['rice'], 2),
            'registered_corn_ha' => round($hectares['corn'], 2),
            'tilled_percent' => $totalHectares > 0 ? round($totalPlanted / $totalHectares * 100) : 0,
            'subsidy_claimed' => $subsidy['beneficiaries_claimed'],
            'subsidy_allocated' => $subsidy['beneficiaries_enrolled'],
            'subsidy_percent' => $subsidy['uptake_percent'],
            'subsidy_unit' => 'Beneficiaries',
            'subsidy_uptake_percent' => $subsidy['uptake_percent'],
            'subsidy_active_campaigns' => $subsidy['active_campaigns'],
            'subsidy_beneficiaries_claimed' => $subsidy['beneficiaries_claimed'],
            'subsidy_beneficiaries_enrolled' => $subsidy['beneficiaries_enrolled'],
            'subsidy_top_campaign' => $subsidy['top_campaign'],
            'subsidy_low_stock_programs' => $subsidy['low_stock_programs'],
            'active_subsidies' => $activeSubsidies,
            'active_calamities' => $threats['active_calamities'],
            'active_pests' => $threats['active_pests'],
            'threat_total' => $threats['total'],
            'threat_critical' => $threats['pest_critical'],
            'threat_moderate' => $threats['pest_moderate'],
            'pest_critical' => $threats['pest_critical'],
            'pest_moderate' => $threats['pest_moderate'],
            'top_pest_name' => $threats['top_pest_name'],
            'dispatches_active' => $threats['dispatches_active'],
            'pending_subsidy_releases' => $pendingSubsidyReleases,
        ];
    }

    /**
     * @return array{male: int, female: int, rsbsa_verified: int}
     */
    private function overviewFarmerGender(): array
    {
        $male = 0;
        $female = 0;
        $rsbsa = 0;

        if (Schema::hasColumn('farmers', 'sex')) {
            $male = Farmer::query()->where('sex', 'Male')->count();
            $female = Farmer::query()->where('sex', 'Female')->count();
        }

        if (Schema::hasColumn('farmers', 'rsbsa_no')) {
            $rsbsa = Farmer::query()
                ->whereNotNull('rsbsa_no')
                ->where('rsbsa_no', '!=', '')
                ->count();
        }

        return [
            'male' => $male,
            'female' => $female,
            'rsbsa_verified' => $rsbsa,
        ];
    }

    /**
     * Active-campaign uptake: claimed vs enrolled beneficiary rows (unit-agnostic).
     *
     * @return array{
     *     claimed: int,
     *     allocated: int,
     *     percent: float,
     *     unit: string,
     *     uptake_percent: float,
     *     active_campaigns: int,
     *     beneficiaries_claimed: int,
     *     beneficiaries_enrolled: int,
     *     top_campaign: array{name: string, percent: float}|null,
     *     low_stock_programs: int
     * }
     */
    private function overviewSubsidyProgress(): array
    {
        $out = [
            'claimed' => 0,
            'allocated' => 0,
            'percent' => 0.0,
            'unit' => 'Beneficiaries',
            'uptake_percent' => 0.0,
            'active_campaigns' => 0,
            'beneficiaries_claimed' => 0,
            'beneficiaries_enrolled' => 0,
            'top_campaign' => null,
            'low_stock_programs' => 0,
        ];

        $hasPrograms = Schema::hasTable('tbl_subsidy_programs');

        if ($hasPrograms) {
            $activeQuery = DB::table('tbl_subsidy_programs');
            if (Schema::hasColumn('tbl_subsidy_programs', 'status')) {
                $activeQuery->where('status', 'Active');
            }
            $out['active_campaigns'] = (int) $activeQuery->count();

            if (Schema::hasColumn('tbl_subsidy_programs', 'remaining_quantity')
                && Schema::hasColumn('tbl_subsidy_programs', 'reorder_level')) {
                $lowStockQuery = DB::table('tbl_subsidy_programs')
                    ->when(
                        Schema::hasColumn('tbl_subsidy_programs', 'status'),
                        fn ($q) => $q->where('status', 'Active')
                    )
                    ->whereNotNull('reorder_level')
                    ->whereColumn('remaining_quantity', '<=', 'reorder_level');
                $out['low_stock_programs'] = (int) $lowStockQuery->count();
            }
        }

        if (! Schema::hasTable('tbl_subsidy_beneficiaries')) {
            return $out;
        }

        $claimed = 0;
        $enrolled = 0;
        $topCampaign = null;
        $topEnrolled = 0;

        if ($hasPrograms) {
            $rowsQuery = DB::table('tbl_subsidy_beneficiaries')
                ->join(
                    'tbl_subsidy_programs',
                    'tbl_subsidy_programs.id',
                    '=',
                    'tbl_subsidy_beneficiaries.program_id'
                )
                ->selectRaw('tbl_subsidy_programs.id as program_id')
                ->selectRaw('tbl_subsidy_programs.program_name as program_name')
                ->selectRaw('COUNT(*) as enrolled')
                ->selectRaw("SUM(CASE WHEN tbl_subsidy_beneficiaries.status = 'Claimed' THEN 1 ELSE 0 END) as claimed")
                ->groupBy('tbl_subsidy_programs.id', 'tbl_subsidy_programs.program_name');
            SubsidyBeneficiary::applyNotDeleted($rowsQuery);
            $rows = $rowsQuery->get();

            foreach ($rows as $row) {
                $programEnrolled = (int) ($row->enrolled ?? 0);
                $programClaimed = (int) ($row->claimed ?? 0);
                $enrolled += $programEnrolled;
                $claimed += $programClaimed;

                if ($programEnrolled <= 0) {
                    continue;
                }

                $percent = round(($programClaimed / $programEnrolled) * 100, 1);
                $isBetter = $topCampaign === null
                    || $percent > $topCampaign['percent']
                    || ($percent === $topCampaign['percent'] && $programEnrolled > $topEnrolled);

                if ($isBetter) {
                    $topCampaign = [
                        'name' => (string) ($row->program_name ?: 'Unnamed program'),
                        'percent' => $percent,
                    ];
                    $topEnrolled = $programEnrolled;
                }
            }
        } else {
            $enrolledQuery = DB::table('tbl_subsidy_beneficiaries');
            SubsidyBeneficiary::applyNotDeleted($enrolledQuery);
            $enrolled = (int) $enrolledQuery->count();
            $claimedQuery = DB::table('tbl_subsidy_beneficiaries')->where('status', 'Claimed');
            SubsidyBeneficiary::applyNotDeleted($claimedQuery);
            $claimed = (int) $claimedQuery->count();
        }

        $uptake = $enrolled > 0 ? round(($claimed / $enrolled) * 100, 1) : 0.0;

        $out['claimed'] = $claimed;
        $out['allocated'] = $enrolled;
        $out['percent'] = $uptake;
        $out['uptake_percent'] = $uptake;
        $out['beneficiaries_claimed'] = $claimed;
        $out['beneficiaries_enrolled'] = $enrolled;
        $out['top_campaign'] = $topCampaign;

        return $out;
    }

    /**
     * Field-threat triage: pests vs pending calamities. Severity is pest-only.
     *
     * @return array{
     *     active_pests: int,
     *     active_calamities: int,
     *     total: int,
     *     critical: int,
     *     moderate: int,
     *     pest_critical: int,
     *     pest_moderate: int,
     *     top_pest_name: string|null,
     *     dispatches_active: int
     * }
     */
    private function overviewThreatIndex(): array
    {
        $pestCritical = 0;
        $pestModerate = 0;
        $activePests = 0;
        $activeCalamities = 0;
        $pestNameCounts = [];
        $technicianIds = [];

        $noteTechnician = function ($id) use (&$technicianIds): void {
            if ($id === null || $id === '') {
                return;
            }
            $technicianIds[(string) $id] = true;
        };

        $notePestName = function ($name) use (&$pestNameCounts): void {
            $label = trim((string) $name);
            if ($label === '') {
                return;
            }
            $pestNameCounts[$label] = ($pestNameCounts[$label] ?? 0) + 1;
        };

        if (Schema::hasTable('pest_outbreaks')) {
            $cols = ['severity'];
            if (Schema::hasColumn('pest_outbreaks', 'pest_name')) {
                $cols[] = 'pest_name';
            }
            if (Schema::hasColumn('pest_outbreaks', 'technician_id')) {
                $cols[] = 'technician_id';
            }

            $outbreaks = PestOutbreak::query()
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->get($cols);
            $activePests += $outbreaks->count();
            foreach ($outbreaks as $row) {
                if ($this->isCriticalSeverity($row->severity ?? null)) {
                    $pestCritical++;
                } else {
                    $pestModerate++;
                }
                $notePestName($row->pest_name ?? '');
                $noteTechnician($row->technician_id ?? null);
            }
        }

        if (Schema::hasTable('pest_monitoring')) {
            $cols = ['severity'];
            if (Schema::hasColumn('pest_monitoring', 'pest_name')) {
                $cols[] = 'pest_name';
            }
            if (Schema::hasColumn('pest_monitoring', 'technician_id')) {
                $cols[] = 'technician_id';
            }

            $monitoring = $this->unverifiedPestQuery()->get($cols);
            $activePests += $monitoring->count();
            foreach ($monitoring as $row) {
                if ($this->isCriticalSeverity($row->severity ?? null)) {
                    $pestCritical++;
                } else {
                    $pestModerate++;
                }
                $notePestName($row->pest_name ?? '');
                $noteTechnician($row->technician_id ?? null);
            }
        }

        if (Schema::hasTable('damage_assessments')) {
            $activeCalamities = (int) DamageAssessment::query()->where('status', 'Pending')->count();

            if (Schema::hasColumn('damage_assessments', 'technician_id')) {
                DamageAssessment::query()
                    ->where('status', 'Pending')
                    ->whereNotNull('technician_id')
                    ->where('technician_id', '!=', '')
                    ->pluck('technician_id')
                    ->each(fn ($id) => $noteTechnician($id));
            }
        }

        $topPestName = null;
        if ($pestNameCounts !== []) {
            arsort($pestNameCounts);
            $topPestName = (string) array_key_first($pestNameCounts);
        }

        return [
            'active_pests' => $activePests,
            'active_calamities' => $activeCalamities,
            'total' => $activePests + $activeCalamities,
            'critical' => $pestCritical,
            'moderate' => $pestModerate,
            'pest_critical' => $pestCritical,
            'pest_moderate' => $pestModerate,
            'top_pest_name' => $topPestName,
            'dispatches_active' => count($technicianIds),
        ];
    }

    private function isCriticalSeverity(?string $severity): bool
    {
        $value = strtolower(trim((string) $severity));

        return str_contains($value, 'high')
            || str_contains($value, 'critical')
            || str_contains($value, 'severe');
    }

    /**
     * Registered parcel area grouped by commodity (soft-deleted plots excluded).
     *
     * @return array{rice: float, corn: float, other: float}
     */
    private function overviewPlotHectares(): array
    {
        $out = ['rice' => 0.0, 'corn' => 0.0, 'other' => 0.0];

        if (! Schema::hasTable('farm_plots')) {
            return $out;
        }

        DB::table('farm_plots')
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(NULLIF(commodity, ''), 'Other') as commodity")
            ->selectRaw('SUM(size_ha) as total_area_ha')
            ->groupByRaw('1')
            ->get()
            ->each(function ($row) use (&$out) {
                $key = $this->commodityBucket((string) $row->commodity);
                $out[$key] += (float) $row->total_area_ha;
            });

        return $out;
    }

    /**
     * Active planted area from planting_logs, grouped by commodity.
     *
     * @return array{rice: float, corn: float, other: float}
     */
    private function overviewActivePlantedHectares(): array
    {
        $out = ['rice' => 0.0, 'corn' => 0.0, 'other' => 0.0];

        if (! Schema::hasTable('planting_logs')) {
            return $out;
        }

        DB::table('planting_logs')
            ->where('status', 'Active')
            ->selectRaw("COALESCE(NULLIF(crop_type, ''), 'Other') as crop")
            ->selectRaw('SUM(area_planted) as total')
            ->groupByRaw('1')
            ->get()
            ->each(function ($row) use (&$out) {
                $key = $this->commodityBucket((string) $row->crop);
                $out[$key] += (float) $row->total;
            });

        return $out;
    }

    private function commodityBucket(string $commodity): string
    {
        $normalized = strtolower(trim($commodity));

        return match (true) {
            str_contains($normalized, 'rice') => 'rice',
            str_contains($normalized, 'corn') => 'corn',
            default => 'other',
        };
    }

    /**
     * Pest reports that still need field validation (missing lat/photo, or Unverified).
     */
    private function unverifiedPestQuery()
    {
        $query = PestMonitoring::query();

        if (Schema::hasColumn('pest_monitoring', 'status')) {
            $query->where('status', 'Unverified');
        } else {
            $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('photo_path');
            });
        }

        return $query;
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
            'crop_stages' => $this->overviewCropStages(),
            'distributions_by_barangay' => $this->overviewDistributionsByBarangay(),
        ];
    }

    /**
     * Municipal crop-stage mix: live planting stages first, then standing-crop
     * (fallback: pest monitoring) for plots without an active planting log.
     *
     * @return array<int, array{stage: string, key: string, total: int, percent: float}>
     */
    private function overviewCropStages(): array
    {
        $counts = app(CropStageService::class)->hybridStageTally();
        $total = array_sum($counts);
        $labels = CropStageService::BUCKET_LABELS;

        return collect($counts)
            ->map(fn ($n, $key) => [
                'stage' => $labels[$key] ?? ucfirst((string) $key),
                'key' => $key,
                'total' => (int) $n,
                'percent' => $total > 0 ? round(($n / $total) * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Crop count/area grouped by registered parcels so unplanted plots still count.
     */
    private function overviewCropDistribution(): array
    {
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
     * Subsidy liquidation % by barangay (claimed / allocated units).
     * Prefer Active campaigns; fall back to all enrolled beneficiaries.
     *
     * @return array<int, array{barangay: string, claimed: int, allocated: int, percent: float, total: int}>
     */
    private function overviewDistributionsByBarangay(): array
    {
        if (! Schema::hasTable('farmers') || ! Schema::hasTable('tbl_subsidy_beneficiaries')) {
            return [];
        }

        $query = DB::table('tbl_subsidy_beneficiaries')
            ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
            ->whereNull('farmers.deleted_at');
        SubsidyBeneficiary::applyNotDeleted($query);

        if (Schema::hasTable('tbl_subsidy_programs')) {
            $query->join(
                'tbl_subsidy_programs',
                'tbl_subsidy_programs.id',
                '=',
                'tbl_subsidy_beneficiaries.program_id'
            )->where('tbl_subsidy_programs.status', 'Active');
        }

        $rows = $query
            ->selectRaw("COALESCE(NULLIF(farmers.permanent_brgy, ''), 'Unspecified') as barangay")
            ->selectRaw('COALESCE(SUM(tbl_subsidy_beneficiaries.calculated_allocation), 0) as allocated')
            ->selectRaw("COALESCE(SUM(CASE WHEN tbl_subsidy_beneficiaries.status = 'Claimed' THEN tbl_subsidy_beneficiaries.calculated_allocation ELSE 0 END), 0) as claimed")
            ->groupByRaw('1')
            ->get();

        if ($rows->isEmpty() && Schema::hasTable('tbl_subsidy_programs')) {
            $fallback = DB::table('tbl_subsidy_beneficiaries')
                ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                ->whereNull('farmers.deleted_at')
                ->selectRaw("COALESCE(NULLIF(farmers.permanent_brgy, ''), 'Unspecified') as barangay")
                ->selectRaw('COALESCE(SUM(tbl_subsidy_beneficiaries.calculated_allocation), 0) as allocated')
                ->selectRaw("COALESCE(SUM(CASE WHEN tbl_subsidy_beneficiaries.status = 'Claimed' THEN tbl_subsidy_beneficiaries.calculated_allocation ELSE 0 END), 0) as claimed")
                ->groupByRaw('1');
            SubsidyBeneficiary::applyNotDeleted($fallback);
            $rows = $fallback->get();
        }

        return $rows
            ->map(function ($row) {
                $allocated = (int) $row->allocated;
                $claimed = (int) $row->claimed;

                return [
                    'barangay' => $row->barangay,
                    'claimed' => $claimed,
                    'allocated' => $allocated,
                    'percent' => $allocated > 0 ? round(($claimed / $allocated) * 100, 1) : 0.0,
                    'total' => $claimed,
                ];
            })
            ->filter(fn ($row) => $row['allocated'] > 0)
            ->sortByDesc('allocated')
            ->take(5)
            ->values()
            ->all();
    }

    private function overviewPredictive(): array
    {
        $harvestForecast = $this->overviewHarvestForecast();
        $weather = $this->overviewWeatherRisk();

        return [
            'season' => $this->currentCropSeason(),
            'harvest_forecast' => $harvestForecast,
            'weather_risk' => $weather['rows'],
            'climate_summary' => $weather['summary'],
        ];
    }

    /**
     * Projected metric tons = active hectares × assumed municipal yield (kg/ha) / 1000.
     * Prefers standing planting logs; falls back to registered farm-plot area.
     *
     * @return array<int, array<string, mixed>>
     */
    private function overviewHarvestForecast(): array
    {
        $buckets = [
            'Rice' => ['area' => 0.0, 'fields' => 0, 'target_ha' => 0.0],
            'Corn' => ['area' => 0.0, 'fields' => 0, 'target_ha' => 0.0],
        ];

        $plotHa = $this->overviewPlotHectares();
        $buckets['Rice']['target_ha'] = $plotHa['rice'];
        $buckets['Corn']['target_ha'] = $plotHa['corn'];

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
                ->get();

            foreach ($rows as $row) {
                $key = match ($this->commodityBucket((string) $row->crop_type)) {
                    'rice' => 'Rice',
                    'corn' => 'Corn',
                    default => null,
                };
                if (! $key) {
                    continue;
                }
                $buckets[$key]['area'] += (float) $row->total_area_ha;
                $buckets[$key]['fields'] += (int) $row->field_count;
            }
        }

        foreach (['Rice', 'Corn'] as $crop) {
            if ($buckets[$crop]['area'] <= 0 && $buckets[$crop]['target_ha'] > 0) {
                $buckets[$crop]['area'] = $buckets[$crop]['target_ha'];
            }
        }

        $forecast = [];
        foreach ($buckets as $crop => $row) {
            $area = (float) $row['area'];
            $targetHa = (float) $row['target_ha'];
            if ($area <= 0 && $targetHa <= 0) {
                continue;
            }
            $yieldPerHa = $this->assumedYieldKgPerHa($crop);
            $kg = $area * $yieldPerHa;
            $targetKg = ($targetHa > 0 ? $targetHa : $area) * $yieldPerHa;

            $forecast[] = [
                'crop_type' => $crop,
                'total_area_ha' => round($area, 2),
                'field_count' => (int) $row['fields'],
                'yield_kg_per_ha' => $yieldPerHa,
                'estimated_harvest_kg' => round($kg, 2),
                'estimated_harvest_mt' => round($kg / 1000, 2),
                'season_target_mt' => round($targetKg / 1000, 2),
            ];
        }

        return $forecast;
    }

    private function currentCropSeason(): string
    {
        $month = (int) Carbon::now()->month;

        return ($month >= 5 && $month <= 10) ? 'Wet' : 'Dry';
    }

    /**
     * 72-hour Open-Meteo cache flags: flood/lodging (precip ≥ 80%) and spray-drift (wind > 15 km/h).
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    private function overviewWeatherRisk(): array
    {
        $weatherRisk = [];
        $highRain = 0;
        $highWind = 0;

        if (! Schema::hasTable('tbl_weather_cache')) {
            return [
                'rows' => [],
                'summary' => [
                    'high_rain_barangays' => 0,
                    'high_wind_barangays' => 0,
                    'horizon_hours' => 72,
                ],
            ];
        }

        $etColumn = Schema::hasColumn('tbl_weather_cache', 'evapotranspiration')
            ? 'evapotranspiration'
            : (Schema::hasColumn('tbl_weather_cache', 'et0_fao_evapotranspiration')
                ? 'et0_fao_evapotranspiration'
                : null);
        $hasWind = Schema::hasColumn('tbl_weather_cache', 'wind_speed_10m');

        $query = WeatherCache::query()
            ->whereDate('forecast_date', '>=', Carbon::today())
            ->whereDate('forecast_date', '<=', Carbon::today()->addDays(3))
            ->where(function ($q) use ($etColumn, $hasWind) {
                $q->where('precipitation_probability', '>=', 80);
                if ($etColumn) {
                    $q->orWhere($etColumn, '>', 5);
                }
                if ($hasWind) {
                    $q->orWhere('wind_speed_10m', '>', 15);
                }
            });

        $select = [
            'barangay_name',
            DB::raw('MAX(precipitation_probability) as max_precip'),
        ];
        if ($etColumn) {
            $select[] = DB::raw("MAX({$etColumn}) as max_et0");
        }
        if ($hasWind) {
            $select[] = DB::raw('MAX(wind_speed_10m) as max_wind');
        }

        $weatherRows = $query
            ->select($select)
            ->groupBy('barangay_name')
            ->orderByDesc('max_precip')
            ->get();

        foreach ($weatherRows as $w) {
            $precip = (int) ($w->max_precip ?? 0);
            $et0 = (float) ($w->max_et0 ?? 0);
            $wind = (float) ($w->max_wind ?? 0);
            $risks = [];
            if ($precip >= 80) {
                $risks[] = 'Flood Risk';
                $highRain++;
            }
            if ($hasWind && $wind > 15) {
                $risks[] = 'Spray Drift';
                $highWind++;
            }
            if ($etColumn && $et0 > 5) {
                $risks[] = 'Drought Risk';
            }
            if (! $risks) {
                continue;
            }

            $primary = $precip >= 80 ? 'Flood Risk' : ($wind > 15 ? 'Spray Drift' : 'Drought Risk');

            $weatherRisk[] = [
                'barangay' => $w->barangay_name,
                'precipitation_probability' => $precip,
                'wind_speed_kmh' => $hasWind ? round($wind, 1) : null,
                'et0' => $etColumn ? round($et0, 3) : null,
                'risks' => $risks,
                'primary_risk' => $primary,
            ];
        }

        return [
            'rows' => $weatherRisk,
            'summary' => [
                'high_rain_barangays' => $highRain,
                'high_wind_barangays' => $highWind,
                'horizon_hours' => 72,
            ],
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
            $precip = (int) ($risk['precipitation_probability'] ?? 0);
            $wind = (float) ($risk['wind_speed_kmh'] ?? 0);

            if (in_array('Flood Risk', $risks, true)) {
                $recommendation = 'Recommend early drainage and delay fertilizer top-dress until floodwater recedes.';
                $alerts[] = $this->makeAlert([
                    'type' => 'weather_alert',
                    'severity' => $precip >= 90 ? 'critical' : 'warning',
                    'barangay' => $barangay,
                    'threat_label' => 'High Lodging Risk',
                    'crop' => 'Rice',
                    'recommendation' => $recommendation,
                    'message' => "High lodging/flood risk in {$barangay} (rain {$precip}%). {$recommendation}",
                ]);
            }

            if (in_array('Spray Drift', $risks, true)) {
                $recommendation = sprintf(
                    'Avoid spray drift — delay chemical spraying (wind %.0f km/h).',
                    $wind
                );
                $alerts[] = $this->makeAlert([
                    'type' => 'weather_alert',
                    'severity' => 'warning',
                    'barangay' => $barangay,
                    'threat_label' => 'Spray Drift',
                    'crop' => null,
                    'recommendation' => $recommendation,
                    'message' => "High wind in {$barangay} ({$wind} km/h). {$recommendation}",
                ]);
            }

            if (in_array('Drought Risk', $risks, true)) {
                $recommendation = 'Recommend irrigation / mulching advisory and staggered watering.';
                $alerts[] = $this->makeAlert([
                    'type' => 'weather_alert',
                    'severity' => 'warning',
                    'barangay' => $barangay,
                    'threat_label' => 'Drought Stress',
                    'crop' => null,
                    'recommendation' => $recommendation,
                    'message' => "High drought risk in {$barangay} (elevated ET0). {$recommendation}",
                ]);
            }
        }

        foreach ($predictive['harvest_forecast'] as $crop) {
            if (($crop['total_area_ha'] ?? 0) >= 50) {
                $mt = $crop['estimated_harvest_mt'] ?? round(($crop['estimated_harvest_kg'] ?? 0) / 1000, 2);
                $alerts[] = $this->makeAlert([
                    'type' => 'harvest_readiness',
                    'severity' => 'warning',
                    'barangay' => null,
                    'threat_label' => 'Harvest Logistics',
                    'crop' => $crop['crop_type'] ?? null,
                    'recommendation' => sprintf(
                        'Stage post-harvest logistics for ~%s MT projected %s yield.',
                        number_format((float) $mt, 1),
                        $crop['crop_type']
                    ),
                    'message' => sprintf(
                        'Large active %s area (%.1f ha). Recommend staging post-harvest logistics for ~%s MT projected yield.',
                        $crop['crop_type'],
                        $crop['total_area_ha'],
                        number_format((float) $mt, 1)
                    ),
                ]);
            }
        }

        $rank = ['critical' => 0, 'warning' => 1];
        usort($alerts, function ($a, $b) use ($rank) {
            return ($rank[$a['severity'] ?? 'warning'] ?? 2) <=> ($rank[$b['severity'] ?? 'warning'] ?? 2);
        });

        $groups = $this->groupAlerts($alerts);

        return [
            'alerts' => array_values($alerts),
            'groups' => array_values($groups),
        ];
    }

    /**
     * Group flat alerts by type + threat_label (+ crop) for the Prescriptive Action Center.
     *
     * @param  array<int, array<string, mixed>>  $alerts
     * @return array<string, array<string, mixed>>
     */
    private function groupAlerts(array $alerts): array
    {
        $categoryMap = [
            'pest_outbreak' => 'outbreak',
            'weather_alert' => 'agro_climate',
            'harvest_readiness' => 'agro_climate',
        ];
        $severityRank = ['critical' => 0, 'warning' => 1];
        $groups = [];

        foreach ($alerts as $alert) {
            $key = ($alert['type'] ?? '') . '|' . ($alert['threat_label'] ?? '') . '|' . ($alert['crop'] ?? '');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => md5($key),
                    'type' => $alert['type'] ?? 'advisory',
                    'threat_label' => $alert['threat_label'] ?? 'Advisory',
                    'crop' => $alert['crop'] ?? null,
                    'severity' => $alert['severity'] ?? 'warning',
                    'category' => $categoryMap[$alert['type'] ?? ''] ?? 'other',
                    'barangays' => [],
                    'recommendation' => $alert['recommendation'] ?? '',
                ];
            }

            if (! empty($alert['barangay']) && ! in_array($alert['barangay'], $groups[$key]['barangays'], true)) {
                $groups[$key]['barangays'][] = $alert['barangay'];
            }

            $existing = $severityRank[$groups[$key]['severity']] ?? 2;
            $incoming = $severityRank[$alert['severity'] ?? 'warning'] ?? 2;
            if ($incoming < $existing) {
                $groups[$key]['severity'] = $alert['severity'];
            }
        }

        foreach ($groups as &$g) {
            $g['count'] = count($g['barangays']);
            $brgyList = $g['count'] > 0
                ? implode(', ', $g['barangays'])
                : 'LGU-wide';
            $g['group_sms_message'] = sprintf(
                'MAO Echague Advisory — %s (%s): %s Affected: %s',
                $g['threat_label'],
                $g['severity'] === 'critical' ? 'CRITICAL' : 'Warning',
                $g['recommendation'],
                $brgyList
            );
        }
        unset($g);

        usort($groups, function ($a, $b) use ($severityRank) {
            $sa = $severityRank[$a['severity'] ?? 'warning'] ?? 2;
            $sb = $severityRank[$b['severity'] ?? 'warning'] ?? 2;
            return $sa <=> $sb;
        });

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $alert
     * @return array<string, mixed>
     */
    private function makeAlert(array $alert): array
    {
        $barangay = $alert['barangay'] ?? null;
        $recommendation = $alert['recommendation'] ?? '';
        $message = $alert['message'] ?? $recommendation;

        return [
            'type' => $alert['type'] ?? 'advisory',
            'severity' => $alert['severity'] ?? 'warning',
            'barangay' => $barangay,
            'threat_label' => $alert['threat_label'] ?? 'Advisory',
            'crop' => $alert['crop'] ?? null,
            'pest_name' => $alert['pest_name'] ?? null,
            'recommendation' => $recommendation,
            'message' => $message,
            'sms_message' => $alert['sms_message'] ?? $this->composeSmsMessage($alert),
        ];
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    private function composeSmsMessage(array $alert): string
    {
        $brgy = $alert['barangay'] ?: 'Echague';
        $label = $alert['threat_label'] ?? 'Advisory';
        $crop = $alert['crop'] ? " ({$alert['crop']})" : '';
        $rec = $alert['recommendation'] ?? $alert['message'] ?? '';

        return Str::limit("MAO Echague Advisory: {$label}{$crop} in {$brgy}. {$rec}", 459, '');
    }

    /**
     * Actionable alerts for currently Active pest outbreaks (compact triage feed).
     */
    private function overviewPestAlerts(): array
    {
        $alerts = [];

        if (Schema::hasTable('pest_outbreaks')) {
            $alerts = PestOutbreak::query()
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->with('farmPlot:id,location_brgy,commodity')
                ->orderByDesc('date_spotted')
                ->limit(8)
                ->get()
                ->map(function ($p) {
                    $brgy = optional($p->farmPlot)->location_brgy;
                    $commodity = optional($p->farmPlot)->commodity;
                    $severity = $this->isCriticalSeverity($p->severity) ? 'critical' : 'warning';
                    $recommendation = (Schema::hasColumn('pest_outbreaks', 'recommended_intervention')
                        ? $p->recommended_intervention
                        : null)
                        ?: $this->shortIntervention((string) $p->pest_name, (string) $p->severity);

                    return $this->makeAlert([
                        'type' => 'pest_outbreak',
                        'severity' => $severity,
                        'barangay' => $brgy,
                        'threat_label' => $p->pest_name ?: 'Pest Outbreak',
                        'crop' => $commodity,
                        'pest_name' => $p->pest_name,
                        'recommendation' => $recommendation,
                        'message' => sprintf(
                            '%s (%s severity) in %s%s. %s',
                            $p->pest_name ?: 'Pest outbreak',
                            $p->severity ?: 'unspecified',
                            $brgy ?: 'an unlisted barangay',
                            $commodity ? " ({$commodity})" : '',
                            $recommendation
                        ),
                    ]);
                })
                ->values()
                ->all();
        }

        if (count($alerts) >= 8 || ! Schema::hasTable('pest_monitoring')) {
            return $alerts;
        }

        $remaining = 8 - count($alerts);
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
                $crop = $p->crop ?? optional($p->farmPlot)->commodity;
                $severity = $this->isCriticalSeverity($p->severity) ? 'critical' : 'warning';
                $recommendation = $p->advisory
                    ?: $this->shortIntervention((string) $p->pest_name, (string) $p->severity);

                return $this->makeAlert([
                    'type' => 'pest_outbreak',
                    'severity' => $severity,
                    'barangay' => $brgy,
                    'threat_label' => $p->pest_name ?: 'Pest Outbreak',
                    'crop' => $crop,
                    'pest_name' => $p->pest_name,
                    'recommendation' => $recommendation,
                    'message' => sprintf(
                        '%s (%s severity) in %s%s. %s',
                        $p->pest_name ?: 'Pest report',
                        $p->severity ?: 'unspecified',
                        $brgy ?: 'an unlisted barangay',
                        $crop ? " ({$crop})" : '',
                        $recommendation
                    ),
                ]);
            })
            ->all();

        return array_values(array_merge($alerts, $monitoring));
    }

    private function shortIntervention(string $pestName, string $severity): string
    {
        $full = $this->resolvePestIntervention($pestName, $severity);
        $clause = trim(explode(';', $full)[0]);

        return $clause !== '' ? $clause : $full;
    }

    private function resolvePestIntervention(string $pestName, string $severity): string
    {
        $interventions = config('pest_guidelines.interventions', []);
        $normalized = Str::lower(trim($pestName));

        $match = collect($interventions)
            ->first(fn ($text, $label) => Str::lower((string) $label) === $normalized);

        $recommendation = $match ?? config('pest_guidelines.default', 'Coordinate with the assigned MAO technician for a site-specific countermeasure.');

        if (in_array($severity, ['High', 'Critical'], true) || $this->isCriticalSeverity($severity)) {
            $escalation = config('pest_guidelines.escalation');
            if ($escalation) {
                $recommendation .= ' '.$escalation;
            }
        }

        return (string) $recommendation;
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
            $farmPlots = $this->mapFarmPlots($barangay, $commodity);
        }

        $damagePoints = [];
        $unmappedCalamityCount = 0;
        if ($wantDamage) {
            $damageQuery = DamageAssessment::with([
                'farmer:id,first_name,surname,permanent_brgy',
                'farmPlot:id,commodity,location_brgy,latitude,longitude',
            ])
                ->when($barangay, function ($q) use ($barangay) {
                    $q->where(function ($sub) use ($barangay) {
                        $sub->whereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $barangay))
                            ->orWhereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay));
                    });
                })
                ->when($commodity, fn ($q) => $q->whereHas('farmPlot', fn ($fp) => $fp->where('commodity', $commodity)));

            $allDamage = $damageQuery->get();
            $damagePoints = $allDamage
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
            $unmappedCalamityCount = max(0, $allDamage->count() - $damagePoints->count());
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

                        $recommendation = (Schema::hasColumn('pest_outbreaks', 'recommended_intervention')
                            ? $p->recommended_intervention
                            : null)
                            ?: $this->shortIntervention((string) $p->pest_name, (string) $p->severity);

                        return [
                            'id' => $p->id,
                            'lat' => (float) $lat,
                            'lng' => (float) $lng,
                            'pest_name' => $p->pest_name,
                            'severity' => $p->severity,
                            'status' => $p->status ?: 'Active',
                            'commodity' => optional($p->farmPlot)->commodity,
                            'brgy' => optional($p->farmPlot)->location_brgy,
                            'date_spotted' => optional($p->date_spotted)?->toDateString(),
                            'recommendation' => $recommendation,
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

                            $recommendation = $p->advisory
                                ?: $this->shortIntervention((string) $p->pest_name, (string) $p->severity);
                            $inspected = Schema::hasColumn('pest_monitoring', 'date_of_inspection')
                                ? optional($p->date_of_inspection)?->toDateString()
                                : optional($p->created_at)?->toDateString();

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
                                'date_spotted' => $inspected,
                                'recommendation' => $recommendation,
                            ];
                        })
                        ->filter()
                        ->values()
                );
            }
        }

        $barangayClimate = $this->overviewBarangayClimate($barangay);
        $floodRiskPoints = collect($barangayClimate)
            ->filter(fn ($row) => (int) ($row['precipitation_probability'] ?? 0) >= 80)
            ->map(fn ($row) => [
                'id' => 'flood-'.$row['barangay'],
                'lat' => $row['lat'],
                'lng' => $row['lng'],
                'brgy' => $row['barangay'],
                'precipitation_probability' => $row['precipitation_probability'],
            ])
            ->filter(fn ($row) => $row['lat'] !== null && $row['lng'] !== null)
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'farm_plots' => $farmPlots,
                'plot_totals' => $this->mapPlotTotals($barangay, $commodity, $farmPlots),
                'damage_points' => $damagePoints,
                'unmapped_calamity_count' => $unmappedCalamityCount,
                'pest_outbreaks' => $pestOutbreaks->values(),
                'flood_risk_points' => $floodRiskPoints,
                'barangay_climate' => $barangayClimate,
            ],
        ]);
    }

    /**
     * Registered parcels with GPS and/or walked boundary polygons.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function mapFarmPlots(?string $barangay, ?string $commodity)
    {
        $plots = FarmPlot::with('farmer:id,first_name,surname,rsbsa_no')
            ->where(function ($q) {
                $q->where(function ($geo) {
                    $geo->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->where(function ($c) {
                            $c->whereRaw('ABS(latitude) > 0.0001')
                                ->orWhereRaw('ABS(longitude) > 0.0001');
                        });
                })->orWhereNotNull('boundary_points');
            })
            ->when($barangay, fn ($q) => $q->where('location_brgy', $barangay))
            ->when($commodity, fn ($q) => $q->where('commodity', $commodity))
            ->get();

        $stageByPlot = [];
        if ($plots->isNotEmpty()) {
            $cropStages = app(CropStageService::class);
            foreach ($cropStages->liveStageBuckets($barangay)['by_plot'] as $plotId => $bucket) {
                $stageByPlot[(string) $plotId] = $cropStages->bucketLabel($bucket);
            }
        }
        if (Schema::hasTable('standing_crop_logs') && $plots->isNotEmpty()) {
            StandingCropLog::query()
                ->whereIn('farm_plot_id', $plots->pluck('id'))
                ->orderByDesc('created_at')
                ->get(['farm_plot_id', 'growth_stage'])
                ->each(function ($row) use (&$stageByPlot) {
                    $id = (string) $row->farm_plot_id;
                    if ($id !== '' && ! isset($stageByPlot[$id])) {
                        $stageByPlot[$id] = $row->growth_stage;
                    }
                });
        }

        return $plots
            ->map(function ($p) use ($stageByPlot) {
                $boundary = $this->normaliseBoundaryPoints($p->boundary_points ?? null);
                $lat = $p->latitude !== null ? (float) $p->latitude : null;
                $lng = $p->longitude !== null ? (float) $p->longitude : null;
                if (! $this->hasValidGps($lat, $lng) && $boundary) {
                    $centroid = $this->polygonCentroid($boundary);
                    $lat = $centroid['lat'];
                    $lng = $centroid['lng'];
                }
                if (! $this->hasValidGps($lat, $lng) && ! $boundary) {
                    return null;
                }

                return [
                    'id' => $p->id,
                    'farmer_id' => $p->farmer_id,
                    'lat' => $lat,
                    'lng' => $lng,
                    'boundary_points' => $boundary,
                    'commodity' => $p->commodity,
                    'size_ha' => $p->size_ha !== null ? (float) $p->size_ha : null,
                    'brgy' => $p->location_brgy,
                    'farmer_name' => trim((optional($p->farmer)->first_name ?? '').' '.(optional($p->farmer)->surname ?? '')),
                    'rsbsa_no' => optional($p->farmer)->rsbsa_no,
                    'georef_id' => $p->georef_id,
                    'geotag_status' => $p->geotag_status ?: (empty($boundary) ? 'unmapped' : 'mapped'),
                    'growth_stage' => $stageByPlot[(string) $p->id] ?? null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Georeferenced parcel count vs all registered plots (soft-deleted excluded).
     *
     * @param  iterable<int, array<string, mixed>>  $farmPlots
     * @return array{mapped: int, total: int}
     */
    private function mapPlotTotals(?string $barangay, ?string $commodity, $farmPlots): array
    {
        $total = (int) FarmPlot::query()
            ->when($barangay, fn ($q) => $q->where('location_brgy', $barangay))
            ->when($commodity, fn ($q) => $q->where('commodity', $commodity))
            ->count();

        $mapped = 0;
        foreach ($farmPlots as $plot) {
            $status = strtolower((string) ($plot['geotag_status'] ?? ''));
            $points = $plot['boundary_points'] ?? [];
            if ($status === 'mapped' || (is_array($points) && count($points) >= 3)) {
                $mapped++;
            }
        }

        return [
            'mapped' => $mapped,
            'total' => $total,
        ];
    }

    /**
     * 72h climate snapshot for every cached barangay (choropleth + inspector).
     *
     * @return array<int, array<string, mixed>>
     */
    private function overviewBarangayClimate(?string $barangay): array
    {
        if (! Schema::hasTable('tbl_weather_cache')) {
            return [];
        }

        $farmerCounts = Farmer::query()
            ->selectRaw("COALESCE(NULLIF(permanent_brgy, ''), 'Unspecified') as barangay")
            ->selectRaw('COUNT(*) as farmer_count')
            ->groupByRaw('1')
            ->pluck('farmer_count', 'barangay');

        $farmerIndex = [];
        foreach ($farmerCounts as $name => $count) {
            $farmerIndex[Str::lower(trim((string) $name))] = (int) $count;
        }

        $hasSoil = Schema::hasColumn('tbl_weather_cache', 'soil_moisture_28cm');
        $hasSoilShallow = Schema::hasColumn('tbl_weather_cache', 'soil_moisture');
        $hasWind = Schema::hasColumn('tbl_weather_cache', 'wind_speed_10m');

        $select = [
            'barangay_name',
            DB::raw('MAX(precipitation_probability) as max_precip'),
        ];
        if ($hasSoil) {
            $select[] = DB::raw('MAX(soil_moisture_28cm) as max_soil_deep');
        }
        if ($hasSoilShallow) {
            $select[] = DB::raw('MAX(soil_moisture) as max_soil');
        }
        if ($hasWind) {
            $select[] = DB::raw('MAX(wind_speed_10m) as max_wind');
        }

        $rows = WeatherCache::query()
            ->whereDate('forecast_date', '>=', Carbon::today())
            ->whereDate('forecast_date', '<=', Carbon::today()->addDays(3))
            ->when($barangay, fn ($q) => $q->where('barangay_name', $barangay))
            ->select($select)
            ->groupBy('barangay_name')
            ->orderBy('barangay_name')
            ->get();

        $pins = [];
        if (Schema::hasTable('tbl_barangays')) {
            $pins = DB::table('tbl_barangays')
                ->get(['name', 'latitude', 'longitude'])
                ->keyBy(fn ($b) => Str::lower(trim((string) $b->name)));
        }

        return $rows->map(function ($w) use ($farmerIndex, $pins, $hasSoil, $hasSoilShallow, $hasWind) {
            $name = (string) $w->barangay_name;
            $key = Str::lower(trim($name));
            $pin = $pins[$key] ?? null;
            $soil = $hasSoil ? ($w->max_soil_deep ?? null) : null;
            if ($soil === null && $hasSoilShallow) {
                $soil = $w->max_soil ?? null;
            }

            return [
                'barangay' => $name,
                'precipitation_probability' => (int) ($w->max_precip ?? 0),
                'soil_moisture' => $soil !== null ? round((float) $soil, 3) : null,
                'wind_speed_kmh' => $hasWind && $w->max_wind !== null ? round((float) $w->max_wind, 1) : null,
                'farmer_count' => $farmerIndex[$key] ?? 0,
                'lat' => $pin?->latitude !== null ? (float) $pin->latitude : null,
                'lng' => $pin?->longitude !== null ? (float) $pin->longitude : null,
            ];
        })->values()->all();
    }

    /**
     * @param  mixed  $raw
     * @return array<int, array{lat: float, lng: float}>
     */
    private function normaliseBoundaryPoints(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $point) {
            if (! is_array($point)) {
                continue;
            }
            $lat = $point['lat'] ?? $point['latitude'] ?? null;
            $lng = $point['lng'] ?? $point['longitude'] ?? null;
            if ($lat === null || $lng === null) {
                continue;
            }
            $out[] = ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        return count($out) >= 3 ? $out : [];
    }

    private function hasValidGps(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        return abs($lat) > 0.0001 || abs($lng) > 0.0001;
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $points
     * @return array{lat: float, lng: float}
     */
    private function polygonCentroid(array $points): array
    {
        $n = count($points);
        $lat = 0.0;
        $lng = 0.0;
        foreach ($points as $p) {
            $lat += (float) $p['lat'];
            $lng += (float) $p['lng'];
        }

        return ['lat' => $lat / max(1, $n), 'lng' => $lng / max(1, $n)];
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
                $planted = optional($row->date_planted)->format('Y-m-d');
                $items->push([
                    'type' => 'Planting',
                    'title' => $farmer !== '' ? $farmer : ($row->crop_type ?: 'Planting log'),
                    'detail' => implode(' · ', array_filter([
                        $row->crop_type ?: 'Crop',
                        $row->variety,
                        $planted,
                        $row->status,
                        $row->water_source,
                    ])),
                    'date_planted' => $planted,
                    'status' => $row->status,
                    'water_source' => $row->water_source,
                    'crop' => $row->crop_type,
                    'variety' => $row->variety,
                    'area_planted' => $row->area_planted,
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        PestMonitoring::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->each(function (PestMonitoring $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Pest',
                    'title' => $farmer !== '' ? $farmer : ($row->pest_name ?: 'Pest report'),
                    'detail' => trim(($row->pest_name ?: $row->crop ?: 'Pest').($row->severity ? ' · '.$row->severity : '')),
                    'created_at' => optional($row->updated_at ?? $row->created_at)?->toIso8601String(),
                ]);
            });

        DamageAssessment::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByRaw('COALESCE(verified_at, updated_at, created_at) DESC')
            ->limit(10)
            ->get()
            ->each(function (DamageAssessment $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Calamity',
                    'title' => $farmer !== '' ? $farmer : ($row->calamity_name ?: 'Damage report'),
                    'detail' => trim(($row->calamity_name ?: $row->calamity_type ?: 'Calamity').($row->status ? ' · '.$row->status : '')),
                    'created_at' => optional($row->verified_at ?? $row->updated_at ?? $row->created_at)?->toIso8601String(),
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
            $techClaims = DB::table('tbl_subsidy_beneficiaries')
                ->leftJoin('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                ->leftJoin('tbl_subsidy_programs', 'tbl_subsidy_programs.id', '=', 'tbl_subsidy_beneficiaries.program_id')
                ->where('tbl_subsidy_beneficiaries.claimed_by', $techId)
                ->orderByDesc('tbl_subsidy_beneficiaries.claimed_at')
                ->limit(10);
            SubsidyBeneficiary::applyNotDeleted($techClaims);
            $techClaims
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

        HarvestLog::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (HarvestLog $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Harvest',
                    'title' => $farmer !== '' ? $farmer : ($row->crop_type ?: 'Harvest log'),
                    'detail' => trim(($row->crop_type ?: 'Crop').' · '.($row->total_yield ?? '').' '.($row->yield_unit ?? '')),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        StandingCropLog::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (StandingCropLog $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Standing crop',
                    'title' => $farmer !== '' ? $farmer : ($row->crop_type ?: 'Standing crop'),
                    'detail' => trim(($row->crop_type ?: 'Crop').($row->growth_stage ? ' · '.$row->growth_stage : '')),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        GeoTagRefusal::with('farmer:id,first_name,surname')
            ->where('technician_id', $techId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (GeoTagRefusal $row) use ($items) {
                $farmer = trim((string) ($row->farmer?->surname ?? '').', '.($row->farmer?->first_name ?? ''), ' ,');
                $items->push([
                    'type' => 'Geo refusal',
                    'title' => $farmer !== '' ? $farmer : 'Refusal',
                    'detail' => 'Attempt '.($row->attempt_number ?? 1),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ]);
            });

        if (Schema::hasTable('field_distribution_logs')) {
            DB::table('field_distribution_logs')
                ->leftJoin('farmers', 'farmers.id', '=', 'field_distribution_logs.farmer_id')
                ->where('field_distribution_logs.technician_id', $techId)
                ->orderByDesc('field_distribution_logs.created_at')
                ->limit(10)
                ->get([
                    'field_distribution_logs.created_at',
                    'field_distribution_logs.item_dispensed',
                    'field_distribution_logs.rsbsa_id',
                    'field_distribution_logs.quantity',
                    'farmers.surname',
                    'farmers.first_name',
                ])
                ->each(function ($row) use ($items) {
                    $farmer = trim((string) ($row->surname ?? '').', '.($row->first_name ?? ''), ' ,');
                    $items->push([
                        'type' => 'Subsidy',
                        'title' => $farmer !== '' ? $farmer : ($row->item_dispensed ?: 'Field dispense'),
                        'detail' => trim(($row->item_dispensed ?: 'Subsidy').' · '.($row->rsbsa_id ?? '').' · '.($row->quantity ?? '')),
                        'created_at' => $row->created_at
                            ? Carbon::parse($row->created_at)->toIso8601String()
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
