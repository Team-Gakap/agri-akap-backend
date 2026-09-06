<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\PestMonitoring;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One-shot slim snapshot for technician offline search / scan / dispatch queues.
 * No photos, polygons, or binary POINT columns — Dexie-friendly only.
 */
class OfflineFieldCacheController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user?->role;
        if (! in_array($role, ['technician', 'admin'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only technicians and admins can download the field cache.',
            ], 403);
        }

        $farmers = Farmer::query()
            ->with(['farmPlots' => function ($q) {
                $q->select('id', 'farmer_id', 'commodity', 'size_ha', 'location_brgy')
                    ->whereNull('deleted_at');
            }])
            ->orderBy('surname')
            ->orderBy('first_name')
            ->get([
                'id',
                'rsbsa_no',
                'qr_code_hash',
                'transaction_code',
                'surname',
                'first_name',
                'middle_name',
                'ext_name',
                'permanent_brgy',
                'mobile_number',
            ])
            ->map(function (Farmer $farmer) {
                $plots = $farmer->farmPlots->map(fn (FarmPlot $p) => [
                    'id' => $p->id,
                    'commodity' => $p->commodity,
                    'size_ha' => (float) $p->size_ha,
                    'location_brgy' => $p->location_brgy,
                ])->values()->all();

                return [
                    'id' => $farmer->id,
                    'rsbsa_no' => $farmer->rsbsa_no,
                    'qr_code_hash' => $farmer->qr_code_hash,
                    'transaction_code' => $farmer->transaction_code,
                    'surname' => $farmer->surname,
                    'first_name' => $farmer->first_name,
                    'middle_name' => $farmer->middle_name,
                    'ext_name' => $farmer->ext_name,
                    'permanent_brgy' => $farmer->permanent_brgy,
                    'mobile_number' => $farmer->mobile_number,
                    'farm_plots' => $plots,
                    'farmPlots' => $plots,
                ];
            })
            ->values()
            ->all();

        $programs = SubsidyProgram::query()
            ->where('status', 'Active')
            ->withCount([
                'beneficiaries',
                'beneficiaries as claimed_count' => fn ($q) => $q->where('status', 'Claimed'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SubsidyProgram $p) => $this->serializeProgram($p))
            ->values()
            ->all();

        $programIds = array_column($programs, 'id');
        $beneficiaries = [];
        if ($programIds) {
            $query = DB::table('tbl_subsidy_beneficiaries')
                ->join('farmers', 'farmers.rsbsa_no', '=', 'tbl_subsidy_beneficiaries.farmer_rsbsa_no')
                ->whereIn('tbl_subsidy_beneficiaries.program_id', $programIds)
                ->whereNull('farmers.deleted_at');
            SubsidyBeneficiary::applyNotDeleted($query, 'tbl_subsidy_beneficiaries.deleted_at');
            $beneficiaries = $query
                ->orderBy('farmers.surname')
                ->orderBy('farmers.first_name')
                ->get([
                    'tbl_subsidy_beneficiaries.id as beneficiary_id',
                    'tbl_subsidy_beneficiaries.program_id',
                    'tbl_subsidy_beneficiaries.farmer_rsbsa_no as rsbsa_no',
                    'tbl_subsidy_beneficiaries.status',
                    'farmers.id as farmer_id',
                    'farmers.surname',
                    'farmers.first_name',
                    'farmers.middle_name',
                ])
                ->map(fn ($row) => [
                    'id' => $row->beneficiary_id,
                    'beneficiary_id' => $row->beneficiary_id,
                    'program_id' => $row->program_id,
                    'farmer_id' => $row->farmer_id,
                    'rsbsa_no' => $row->rsbsa_no,
                    'surname' => $row->surname,
                    'first_name' => $row->first_name,
                    'middle_name' => $row->middle_name,
                    'status' => $row->status,
                ])
                ->values()
                ->all();
        }

        $pest = PestMonitoring::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,ext_name,permanent_brgy',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('photo_path');
            })
            ->orderByDesc('date_of_inspection')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $calamity = DamageAssessment::query()
            ->with([
                'farmer:id,first_name,surname,middle_name,ext_name,rsbsa_no,permanent_brgy,mobile_number',
                'farmPlot:id,commodity,size_ha,location_brgy',
            ])
            ->where(function ($q) {
                $q->whereNull('photo_evidence_path')->orWhereNull('latitude');
            })
            ->orderByDesc('date_of_calamity')
            ->limit(500)
            ->get();

        $geotagQuery = FarmPlot::with([
            'farmer:id,first_name,middle_name,surname,ext_name,rsbsa_no,permanent_brgy',
            'assignedTechnician:id,name',
        ])->pendingFieldGeotag();

        if ($role === 'technician') {
            $geotagQuery->where('geotag_assigned_user_id', $user->id);
        }

        $geotag = $geotagQuery
            ->orderByRaw("CASE WHEN geotag_priority = 'urgent' THEN 0 ELSE 1 END")
            ->orderBy('geotag_deadline')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Field cache snapshot ready.',
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'farmers' => $farmers,
                'programs' => $programs,
                'beneficiaries' => $beneficiaries,
                'queues' => [
                    'pest' => $pest,
                    'calamity' => $calamity,
                    'geotag' => $geotag,
                ],
            ],
        ]);
    }

    private function serializeProgram(SubsidyProgram $p): array
    {
        $reorder = $p->reorder_level !== null ? (float) $p->reorder_level : null;
        $remaining = (float) $p->remaining_quantity;
        $secondaryReorder = $p->secondary_reorder_level !== null ? (float) $p->secondary_reorder_level : null;
        $secondaryRemaining = $p->secondary_remaining_quantity !== null
            ? (float) $p->secondary_remaining_quantity
            : null;
        $isLowStock = ($reorder !== null && $remaining <= $reorder)
            || ($p->secondary_unit && $secondaryReorder !== null && $secondaryRemaining !== null && $secondaryRemaining <= $secondaryReorder);

        return [
            'id' => $p->id,
            'program_name' => $p->program_name,
            'target_crop' => $p->target_crop,
            'target_barangays' => $p->target_barangays,
            'seed_class' => $p->seed_class,
            'item_type' => $p->item_type,
            'max_hectares_limit' => (float) $p->max_hectares_limit,
            'min_hectares_limit' => (float) ($p->min_hectares_limit ?? 0),
            'items_per_hectare' => (float) $p->items_per_hectare,
            'secondary_items_per_hectare' => $p->secondary_items_per_hectare !== null
                ? (float) $p->secondary_items_per_hectare
                : null,
            'status' => $p->status,
            'unit_of_measurement' => $p->unit_of_measurement,
            'secondary_unit' => $p->secondary_unit,
            'total_quantity' => (float) $p->total_quantity,
            'remaining_quantity' => $remaining,
            'reorder_level' => $reorder,
            'secondary_total_quantity' => $p->secondary_total_quantity !== null
                ? (float) $p->secondary_total_quantity
                : null,
            'secondary_remaining_quantity' => $secondaryRemaining,
            'secondary_reorder_level' => $secondaryReorder,
            'is_low_stock' => $isLowStock,
            'beneficiaries_count' => (int) ($p->beneficiaries_count ?? 0),
            'claimed_count' => (int) ($p->claimed_count ?? 0),
            'created_at' => optional($p->created_at)->toIso8601String(),
        ];
    }
}
