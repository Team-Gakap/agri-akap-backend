<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\PestMonitoring;
use App\Models\PlantingLog;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
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
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date'],
        ]);

        $query = SubsidyBeneficiary::query()
            ->where('tbl_subsidy_beneficiaries.status', 'Claimed')
            ->join('tbl_subsidy_programs', 'tbl_subsidy_programs.id', '=', 'tbl_subsidy_beneficiaries.program_id')
            ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
            ->select([
                'tbl_subsidy_beneficiaries.id',
                'tbl_subsidy_beneficiaries.farmer_rsbsa_no',
                'tbl_subsidy_beneficiaries.calculated_allocation',
                'tbl_subsidy_beneficiaries.claimed_at',
                'tbl_subsidy_programs.program_name',
                'tbl_subsidy_programs.target_crop',
                'tbl_subsidy_programs.unit_of_measurement',
                'farmers.surname',
                'farmers.first_name',
                'farmers.permanent_brgy',
            ])
            ->orderBy('tbl_subsidy_beneficiaries.claimed_at', 'desc');

        if ($request->filled('program_id')) {
            $query->where('tbl_subsidy_beneficiaries.program_id', $request->program_id);
        }
        if ($request->filled('crop_type')) {
            $crop = $request->crop_type;
            $query->where(function ($q) use ($crop) {
                $q->where('tbl_subsidy_programs.target_crop', $crop)
                    ->orWhere('tbl_subsidy_programs.target_crop', 'Both');
            });
        }
        if ($request->filled('barangay')) {
            $query->where('farmers.permanent_brgy', $request->barangay);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('tbl_subsidy_beneficiaries.claimed_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('tbl_subsidy_beneficiaries.claimed_at', '<=', $request->date_to);
        }

        $rows = $query->limit(3000)->get()->map(function ($row) {
            $itemReceived = trim($row->calculated_allocation . ' ' . ($row->unit_of_measurement ?? 'Bags'));
            $farmerName   = trim(($row->first_name ?? '') . ' ' . ($row->surname ?? ''));

            return [
                'rsbsa_no'      => $row->farmer_rsbsa_no,
                'farmer_name'   => $farmerName,
                'barangay'      => $row->permanent_brgy ?? '',
                'program_name'  => $row->program_name ?? '',
                'target_crop'   => $row->target_crop ?? '',
                'item_received' => $itemReceived,
                'quantity'      => (float) ($row->calculated_allocation ?? 0),
                'unit'          => $row->unit_of_measurement ?? 'Bags',
                'date_claimed'  => $row->claimed_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => ['rows' => $rows],
        ]);
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

        $rows = $mode === 'harvest'
            ? $this->harvestRows($request->all())
            : $this->plantingRows($request->all());

        return response()->json([
            'status' => 'success',
            'data'   => ['mode' => $mode, 'rows' => $rows],
        ]);
    }

    private function plantingRows(array $f): array
    {
        $query = PlantingLog::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,permanent_brgy',
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
            $name   = trim(($farmer?->first_name ?? '') . ' ' . ($farmer?->surname ?? ''));

            return [
                'rsbsa_no'     => $farmer?->rsbsa_no ?? '',
                'name'         => $name,
                'farm_location'=> $log->farm_location ?: ($log->farmPlot?->location_brgy ?? $farmer?->permanent_brgy ?? ''),
                'crop'         => $log->crop_type ?? '',
                'variety'      => $log->variety ?? '',
                'area_planted' => (float) ($log->area_planted ?? 0),
                'date_planted' => optional($log->date_planted)->format('Y-m-d'),
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
                    'farmers.rsbsa_no',
                    DB::raw("TRIM(CONCAT(COALESCE(farmers.first_name,''), ' ', COALESCE(farmers.surname,''))) AS name"),
                    DB::raw("COALESCE(harvest_logs.farm_location, farm_plots.location_brgy, farmers.permanent_brgy, '') AS farm_location"),
                    DB::raw("COALESCE(harvest_logs.crop_type, farm_plots.commodity, '') AS crop"),
                    DB::raw("COALESCE(harvest_logs.variety, '') AS variety"),
                    DB::raw('COALESCE(harvest_logs.area_harvested, 0) AS area_harvested'),
                    DB::raw('COALESCE(harvest_logs.total_yield, 0) AS total_yield'),
                    'harvest_logs.date_harvested',
                ])
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

            return $query->limit(3000)->get()->map(fn ($row) => [
                'rsbsa_no'       => $row->rsbsa_no,
                'name'           => $row->name,
                'farm_location'  => $row->farm_location,
                'crop'           => $row->crop,
                'variety'        => $row->variety,
                'area_harvested' => (float) $row->area_harvested,
                'total_yield'    => (float) $row->total_yield,
                'date_harvested' => $row->date_harvested,
            ])->values()->all();

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
                'farmer:id,rsbsa_no,surname,first_name,permanent_brgy',
                'farmPlot:id,location_brgy,commodity',
            ])
            ->orderByRaw('COALESCE(date_of_inspection, DATE(pest_monitoring.created_at)) DESC');

        if ($request->filled('barangay')) {
            $query->whereHas('farmer', fn ($q) => $q->where('permanent_brgy', $request->barangay));
        }
        if ($request->filled('crop_type')) {
            $query->where('crop', $request->crop_type);
        }

        // Map UI status labels to DB values
        if ($request->filled('status')) {
            $st = $request->status;
            $query->where(function ($q) use ($st) {
                if ($st === 'Responded') {
                    $q->where('is_outbreak', true)
                      ->orWhere('item_distributed', '!=', null);
                } elseif ($st === 'Validated') {
                    $q->where('report_ref', '!=', null);
                } else {
                    // Pending — no outbreak flag, no report ref
                    $q->where('is_outbreak', false)
                      ->whereNull('report_ref');
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
            $name       = trim(($farmer?->first_name ?? '') . ' ' . ($farmer?->surname ?? ''));
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
                'date_reported' => $reportDate,
                'barangay'      => $farmer?->permanent_brgy ?? $row->farmPlot?->location_brgy ?? '',
                'farmer_name'   => $name,
                'farm_location' => $row->farm_location ?: ($row->farmPlot?->location_brgy ?? $farmer?->permanent_brgy ?? ''),
                'crop'          => $row->crop ?? '',
                'pest_disease'  => $row->pest_name ?? '',
                'severity'      => $row->severity ?? 'Low',
                'area_affected' => $areaAffected,
                'status'        => $status,
                'photo_url'     => $row->photo_path
                    ? asset('storage/' . ltrim($row->photo_path, '/'))
                    : null,
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
                'farmer:id,rsbsa_no,surname,first_name,permanent_brgy',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->orderBy('date_of_calamity', 'desc');

        if ($request->filled('barangay')) {
            $brgy = $request->barangay;
            $query->where(function ($q) use ($brgy) {
                $q->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $brgy))
                  ->orWhereHas('farmPlot', fn ($fp) => $fp->where('location_brgy', $brgy));
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
            $name   = trim(($farmer?->first_name ?? '') . ' ' . ($farmer?->surname ?? ''));
            $brgy   = $farmer?->permanent_brgy ?? $row->farmPlot?->location_brgy ?? '';
            $effectiveStatus = $this->effectiveDamageStatus($row);
            $areaAffected = (float) ($row->area_destroyed_ha ?? 0);
            if ($areaAffected <= 0) {
                $base = (float) ($row->area_planted_ha ?? $row->farmPlot?->size_ha ?? 0);
                $areaAffected = round($base * ((float) ($row->damage_percentage ?? 0) / 100), 4);
            }

            return [
                'date_reported'  => optional($row->date_of_calamity)->format('Y-m-d'),
                'barangay'       => $brgy,
                'farmer_name'    => $name,
                'farm_location'  => $row->farmPlot?->location_brgy ?? $brgy,
                'crop'           => $row->farmPlot?->commodity ?? '',
                'calamity_type'  => $row->calamity_type ?? $row->calamity_name ?? '',
                'area_affected'  => $areaAffected,
                'damage_value'   => (float) ($row->estimated_value_lost ?? 0),
                'status'         => $effectiveStatus,
                'photo_url'      => $row->photo_evidence_path
                    ? asset('storage/' . ltrim($row->photo_evidence_path, '/'))
                    : null,
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
}
