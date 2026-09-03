<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\PestMonitoring;
use App\Models\PlantingLog;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Support\SubsidyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportsController extends Controller
{
    // ── Subsidy Distribution Report ───────────────────────────────────────────

    /**
     * GET /reports/subsidies
     * Returns all claimed subsidy beneficiaries joined with their program data.
     */
    public function subsidies(Request $request): JsonResponse
    {
        $request->validate([
            'program_id' => ['nullable', 'string'],
            'barangay'   => ['nullable', 'string'],
            'crop_type'  => ['nullable', 'string'],
            'seed_class' => ['nullable', 'string'],
            'item_type'  => ['nullable', 'string'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date'],
        ]);

        $query = SubsidyBeneficiary::query()
            ->where('tbl_subsidy_beneficiaries.status', 'Claimed')
            ->join('tbl_subsidy_programs', 'tbl_subsidy_programs.id', '=', 'tbl_subsidy_beneficiaries.program_id')
            ->leftJoin('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
            ->select([
                'tbl_subsidy_beneficiaries.id',
                'tbl_subsidy_beneficiaries.farmer_rsbsa_no',
                'tbl_subsidy_beneficiaries.calculated_allocation',
                'tbl_subsidy_beneficiaries.calculated_allocation_secondary',
                'tbl_subsidy_beneficiaries.claimed_at',
                'tbl_subsidy_beneficiaries.photo_proof_path',
                'tbl_subsidy_programs.program_name',
                'tbl_subsidy_programs.target_crop',
                'tbl_subsidy_programs.seed_class',
                'tbl_subsidy_programs.item_type',
                'tbl_subsidy_programs.unit_of_measurement',
                'tbl_subsidy_programs.secondary_unit',
                'farmers.surname',
                'farmers.first_name',
                'farmers.middle_name',
                'farmers.permanent_brgy',
            ])
            ->orderBy('tbl_subsidy_beneficiaries.claimed_at', 'desc');
        SubsidyBeneficiary::applyNotDeleted($query);

        if ($request->filled('program_id')) {
            $query->where('tbl_subsidy_beneficiaries.program_id', $request->program_id);
        }
        if ($request->filled('crop_type')) {
            $crop = (string) $request->crop_type;
            $query->where(function ($q) use ($crop) {
                $q->where('tbl_subsidy_programs.target_crop', $crop)
                    ->orWhereRaw('LOWER(tbl_subsidy_programs.target_crop) = ?', ['both']);
            });
        }
        if ($request->filled('seed_class')) {
            $query->where('tbl_subsidy_programs.seed_class', $request->seed_class);
        }
        if ($request->filled('item_type')) {
            $query->where('tbl_subsidy_programs.item_type', $request->item_type);
        }
        $barangay = $this->scopedBarangay($request);
        if ($barangay !== null) {
            $query->where('farmers.permanent_brgy', $barangay);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('tbl_subsidy_beneficiaries.claimed_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('tbl_subsidy_beneficiaries.claimed_at', '<=', $request->date_to);
        }

        $rows = $query->limit(3000)->get()->map(function ($row) {
            $names = $this->splitFarmerName($row->surname ?? '', $row->first_name ?? '', $row->middle_name ?? '');

            return [
                'id'            => $row->id,
                'rsbsa_no'      => $row->farmer_rsbsa_no,
                'surname'       => $names['surname'],
                'first_name'    => $names['first_name'],
                'middle_name'   => $names['middle_name'],
                'farmer_name'   => $names['display'],
                'barangay'      => $row->permanent_brgy ?? '',
                'program_name'  => $row->program_name ?? '',
                'target_crop'   => $row->target_crop ?? '',
                'seed_class'    => $row->seed_class,
                'item_type'     => $row->item_type,
                'item_received' => $this->formatItemReceived($row),
                'quantity'      => (float) ($row->calculated_allocation ?? 0),
                'unit'          => $row->unit_of_measurement ?? 'Bags',
                'quantity_secondary' => $row->calculated_allocation_secondary !== null
                    ? (float) $row->calculated_allocation_secondary
                    : null,
                'unit_secondary' => $row->secondary_unit,
                'date_claimed'  => $row->claimed_at,
                'photo_path'    => $row->photo_proof_path,
                'photo_url'     => public_storage_url($row->photo_proof_path),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => ['rows' => $rows],
        ]);
    }

    /**
     * "40 kg / 2 bags", "3 bottle", or "₱1,000" depending on the catalog
     * shape of the item; legacy rows fall back to a single quantity + unit.
     */
    private function formatItemReceived($row): string
    {
        $unit = $row->unit_of_measurement ?? 'Bags';
        $qty = (float) ($row->calculated_allocation ?? 0);

        if (($row->item_type ?? null) && SubsidyCatalog::isCash($row->item_type)) {
            return '₱' . number_format($qty, 0);
        }
        if (strcasecmp(trim((string) $unit), 'Cash (PHP)') === 0) {
            return '₱' . number_format($qty, 0);
        }

        $primary = trim($this->trimTrailingZero($qty) . ' ' . $unit);

        if ($row->secondary_unit && $row->calculated_allocation_secondary !== null) {
            $secondaryQty = (float) $row->calculated_allocation_secondary;
            $primary .= ' / ' . trim($this->trimTrailingZero($secondaryQty) . ' ' . $row->secondary_unit);
        }

        return $primary;
    }

    /**
     * Display quantities as whole numbers when they have no fractional part
     * (e.g. "40 kg" instead of "40.00 kg"), otherwise keep 2 decimals.
     */
    private function trimTrailingZero(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? number_format($value, 0)
            : number_format($value, 2);
    }

    // ── Crop Production Report ────────────────────────────────────────────────

    /**
     * GET /reports/crop-production?mode=planting|harvest
     * Returns planting or harvest data with per-mode column shape.
     */
    public function cropProduction(Request $request): JsonResponse
    {
        $request->validate([
            'mode'      => ['nullable', 'string', 'in:planting,harvest'],
            'barangay'  => ['nullable', 'string'],
            'crop_type' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
        ]);

        $mode = $request->input('mode', 'planting');
        $filters = $request->all();
        $barangay = $this->scopedBarangay($request);
        if ($barangay !== null) {
            $filters['barangay'] = $barangay;
        }

        $rows = $mode === 'harvest'
            ? $this->harvestRows($filters)
            : $this->plantingRows($filters);

        return response()->json([
            'status' => 'success',
            'data'   => ['mode' => $mode, 'rows' => $rows],
        ]);
    }

    private function plantingRows(array $f): array
    {
        $query = PlantingLog::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,permanent_brgy',
                'farmPlot:id,location_brgy,commodity',
            ])
            ->orderBy('date_planted', 'desc');

        if (! empty($f['barangay'])) {
            $query->whereHas('farmer', fn ($q) => $q->where('permanent_brgy', $f['barangay']));
        }
        if (! empty($f['crop_type'])) {
            $query->where('crop_type', $f['crop_type']);
        }
        if (! empty($f['date_from'])) {
            $query->whereDate('date_planted', '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $query->whereDate('date_planted', '<=', $f['date_to']);
        }

        return $query->limit(3000)->get()->map(function (PlantingLog $log) {
            $farmer = $log->farmer;
            $names = $this->splitFarmerName(
                $farmer?->surname ?? '',
                $farmer?->first_name ?? '',
                $farmer?->middle_name ?? '',
            );

            return [
                'id'           => $log->id,
                'rsbsa_no'     => $farmer?->rsbsa_no ?? '',
                'surname'      => $names['surname'],
                'first_name'   => $names['first_name'],
                'middle_name'  => $names['middle_name'],
                'name'         => $names['display'],
                'farm_location'=> $log->farm_location ?: ($log->farmPlot?->location_brgy ?? $farmer?->permanent_brgy ?? ''),
                'crop'         => $log->crop_type ?? '',
                'variety'      => $log->variety ?? '',
                'area_planted' => (float) ($log->area_planted ?? 0),
                'date_planted' => optional($log->date_planted)->format('Y-m-d'),
                'status'       => $log->status ?? '',
                'water_source' => $log->water_source ?? '',
            ];
        })->values()->all();
    }

    private function harvestRows(array $f): array
    {
        // Try harvest_logs table if it exists; gracefully return empty otherwise.
        try {
            $query = DB::table('harvest_logs')
                ->join('farmers', 'farmers.id', '=', 'harvest_logs.farmer_id')
                ->leftJoin('farm_plots', 'farm_plots.id', '=', 'harvest_logs.farm_plot_id')
                ->select([
                    'harvest_logs.id',
                    'farmers.rsbsa_no',
                    'farmers.surname',
                    'farmers.first_name',
                    'farmers.middle_name',
                    DB::raw("TRIM(CONCAT(COALESCE(farmers.first_name,''), ' ', COALESCE(farmers.surname,''))) AS name"),
                    DB::raw("COALESCE(harvest_logs.farm_location, farm_plots.location_brgy, farmers.permanent_brgy, '') AS farm_location"),
                    DB::raw("COALESCE(harvest_logs.crop_type, farm_plots.commodity, '') AS crop"),
                    DB::raw("COALESCE(harvest_logs.variety, '') AS variety"),
                    DB::raw('COALESCE(harvest_logs.area_harvested, 0) AS area_harvested'),
                    DB::raw('COALESCE(harvest_logs.total_yield, 0) AS total_yield'),
                    'harvest_logs.date_harvested',
                ])
                ->whereNull('harvest_logs.deleted_at')
                ->orderBy('harvest_logs.date_harvested', 'desc');

            if (! empty($f['barangay'])) {
                $query->where('farmers.permanent_brgy', $f['barangay']);
            }
            if (! empty($f['crop_type'])) {
                $query->where(function ($q) use ($f) {
                    $q->where('harvest_logs.crop_type', $f['crop_type'])
                      ->orWhere('farm_plots.commodity', $f['crop_type']);
                });
            }
            if (! empty($f['date_from'])) {
                $query->whereDate('harvest_logs.date_harvested', '>=', $f['date_from']);
            }
            if (! empty($f['date_to'])) {
                $query->whereDate('harvest_logs.date_harvested', '<=', $f['date_to']);
            }

            return $query->limit(3000)->get()->map(function ($row) {
                $names = $this->splitFarmerName(
                    $row->surname ?? '',
                    $row->first_name ?? '',
                    $row->middle_name ?? '',
                );

                return [
                'id'             => $row->id,
                'rsbsa_no'       => $row->rsbsa_no,
                'surname'        => $names['surname'],
                'first_name'     => $names['first_name'],
                'middle_name'    => $names['middle_name'],
                'name'           => $names['display'],
                'farm_location'  => $row->farm_location,
                'crop'           => $row->crop,
                'variety'        => $row->variety,
                'area_harvested' => (float) $row->area_harvested,
                'total_yield'    => (float) $row->total_yield,
                'date_harvested' => $row->date_harvested,
            ];
            })->values()->all();

        } catch (\Exception) {
            // harvest_logs table does not exist yet — return empty result.
            return [];
        }
    }

    // ── Pest Surveillance Report ──────────────────────────────────────────────

    /**
     * GET /reports/pest-surveillance
     * Returns pest monitoring records with photo evidence URL.
     */
    public function pestSurveillance(Request $request): JsonResponse
    {
        $request->validate([
            'barangay'  => ['nullable', 'string'],
            'crop_type' => ['nullable', 'string'],
            'status'    => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
        ]);

        $query = PestMonitoring::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,permanent_brgy',
                'farmPlot:id,location_brgy,commodity',
            ])
            ->orderByRaw('COALESCE(date_of_inspection, DATE(pest_monitoring.created_at)) DESC');

        if ($request->filled('crop_type')) {
            $query->where('crop', $request->crop_type);
        }
        $barangay = $this->scopedBarangay($request);
        if ($barangay !== null) {
            $query->whereHas('farmer', fn ($q) => $q->where('permanent_brgy', $barangay));
        }

        // Validated = field-validated (lat + photo). Default All so newly encoded
        // barangay records (no GPS/photo yet) still appear for admin and brgy.
        $st = $request->input('status', 'All');
        if ($st && $st !== 'All') {
            $query->where(function ($q) use ($st) {
                if ($st === 'Responded') {
                    $q->whereNotNull('item_distributed')->where('item_distributed', '!=', '');
                } elseif ($st === 'Pending') {
                    $q->where(function ($inner) {
                        $inner->whereNull('latitude')->orWhereNull('photo_path');
                    })->where(function ($inner) {
                        $inner->whereNull('item_distributed')->orWhere('item_distributed', '');
                    });
                } else {
                    // Validated
                    $q->whereNotNull('latitude')
                        ->whereNotNull('photo_path')
                        ->where(function ($inner) {
                            $inner->whereNull('item_distributed')->orWhere('item_distributed', '');
                        });
                }
            });
        }

        if ($request->filled('date_from')) {
            $from = $request->date_from;
            $query->where(function ($q) use ($from) {
                $q->whereDate('date_of_inspection', '>=', $from)
                  ->orWhere(function ($q2) use ($from) {
                      $q2->whereNull('date_of_inspection')
                         ->whereDate('pest_monitoring.created_at', '>=', $from);
                  });
            });
        }
        if ($request->filled('date_to')) {
            $to = $request->date_to;
            $query->where(function ($q) use ($to) {
                $q->whereDate('date_of_inspection', '<=', $to)
                  ->orWhere(function ($q2) use ($to) {
                      $q2->whereNull('date_of_inspection')
                         ->whereDate('pest_monitoring.created_at', '<=', $to);
                  });
            });
        }

        $rows = $query->limit(3000)->get()->map(function (PestMonitoring $row) {
            $farmer     = $row->farmer;
            $names = $this->splitFarmerName(
                $farmer?->surname ?? '',
                $farmer?->first_name ?? '',
                $farmer?->middle_name ?? '',
            );
            $reportDate = optional($row->date_of_inspection)->format('Y-m-d')
                ?: optional($row->created_at)->format('Y-m-d');

            $status = 'Pending';
            if ($row->item_distributed) {
                $status = 'Responded';
            } elseif ($row->latitude && $row->photo_path) {
                $status = 'Validated';
            }

            $planted = (float) ($row->area_planted ?? 0);
            $pct = (float) ($row->area_damage_pct ?? $row->incidence ?? 0);
            $areaAffected = $planted > 0 ? round($planted * ($pct / 100), 4) : 0.0;

            return [
                'id'            => $row->id,
                'date_reported' => $reportDate,
                'barangay'      => $farmer?->permanent_brgy ?? $row->farmPlot?->location_brgy ?? '',
                'surname'       => $names['surname'],
                'first_name'    => $names['first_name'],
                'middle_name'   => $names['middle_name'],
                'farmer_name'   => $names['display'],
                'farm_location' => $row->farm_location ?: ($row->farmPlot?->location_brgy ?? $farmer?->permanent_brgy ?? ''),
                'crop'           => $row->crop ?? '',
                'variety'        => $row->variety ?? '',
                'pest_disease'  => $row->pest_name ?? '',
                'severity'      => $row->severity ?? 'Low',
                'area_affected' => $areaAffected,
                'area_damage_pct' => $pct,
                'status'        => $status,
                'photo_url'     => public_storage_url($row->photo_path),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => ['rows' => $rows],
        ]);
    }

    // ── Damage & Calamity Report ──────────────────────────────────────────────

    /**
     * GET /reports/damage-calamity
     * Returns damage assessment records with photo evidence URL.
     */
    public function damageCalamity(Request $request): JsonResponse
    {
        $request->validate([
            'barangay'      => ['nullable', 'string'],
            'crop_type'     => ['nullable', 'string'],
            'calamity_type' => ['nullable', 'string'],
            'status'        => ['nullable', 'string'],
            'date_from'     => ['nullable', 'date'],
            'date_to'       => ['nullable', 'date'],
        ]);

        $query = DamageAssessment::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,permanent_brgy',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->orderBy('date_of_calamity', 'desc');

        $barangay = $this->scopedBarangay($request);
        if ($barangay !== null) {
            $query->where(function ($q) use ($barangay) {
                $q->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $barangay))
                  ->orWhereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $barangay));
            });
        }
        if ($request->filled('crop_type')) {
            $query->whereHas('farmPlot', fn ($fp) => $fp->where('commodity', $request->crop_type));
        }
        if ($request->filled('calamity_type')) {
            $query->where('calamity_type', $request->calamity_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date_of_calamity', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date_of_calamity', '<=', $request->date_to);
        }

        $rows = $query->limit(3000)->get()->map(function (DamageAssessment $row) {
            $farmer = $row->farmer;
            $names = $this->splitFarmerName(
                $farmer?->surname ?? '',
                $farmer?->first_name ?? '',
                $farmer?->middle_name ?? '',
            );
            $brgy   = $farmer?->permanent_brgy ?? $row->farmPlot?->location_brgy ?? '';
            $effectiveStatus = $this->effectiveDamageStatus($row);
            $areaAffected = (float) ($row->area_destroyed_ha ?? 0);
            if ($areaAffected <= 0) {
                $base = (float) ($row->area_planted_ha ?? $row->farmPlot?->size_ha ?? 0);
                $areaAffected = round($base * ((float) ($row->damage_percentage ?? 0) / 100), 4);
            }

            return [
                'id'             => $row->id,
                'date_reported'  => optional($row->date_of_calamity)->format('Y-m-d'),
                'barangay'       => $brgy,
                'surname'        => $names['surname'],
                'first_name'     => $names['first_name'],
                'middle_name'    => $names['middle_name'],
                'farmer_name'    => $names['display'],
                'farm_location'  => $row->farmPlot?->location_brgy ?? $brgy,
                'crop'           => $row->farmPlot?->commodity ?? '',
                'calamity_type'  => $row->calamity_type ?? '',
                'calamity_name'  => $row->calamity_name ?? '',
                'area_affected'  => $areaAffected,
                'damage_percentage' => (float) ($row->damage_percentage ?? 0),
                'damage_value'   => (float) ($row->estimated_value_lost ?? 0),
                'status'         => $effectiveStatus,
                'photo_url'      => public_storage_url($row->photo_evidence_path),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => ['rows' => $rows],
        ]);
    }

    /**
     * Technician field validation requires geotagged photo evidence.
     * Legacy barangay-encoded rows may still be marked Verified in DB without it.
     */
    private function effectiveDamageStatus(DamageAssessment $row): string
    {
        $status = $row->status ?? 'Pending';

        if ($status === 'Verified' && (empty($row->photo_evidence_path) || $row->latitude === null)) {
            return 'Pending';
        }

        return $status;
    }

    /**
     * @return array{surname: string, first_name: string, middle_name: string, display: string}
     */
    private function splitFarmerName(string $surname, string $firstName, string $middleName): array
    {
        $surname = trim($surname);
        $firstName = trim($firstName);
        $middleName = trim($middleName);
        $display = trim(implode(' ', array_filter([$firstName, $middleName, $surname])));

        return [
            'surname' => $surname,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'display' => $display,
        ];
    }

    /**
     * Barangay officials are always locked to assigned_barangay.
     * Admins use the optional request barangay filter.
     */
    private function scopedBarangay(Request $request): ?string
    {
        $user = $request->user();
        if ($user?->role === 'barangay_official') {
            $assigned = trim((string) ($user->assigned_barangay ?? ''));

            return $assigned !== '' ? $assigned : '__unassigned__';
        }

        return $request->filled('barangay') ? (string) $request->barangay : null;
    }
}
