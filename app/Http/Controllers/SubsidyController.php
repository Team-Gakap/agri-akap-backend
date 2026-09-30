<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Support\OfficialBarangays;
use App\Support\SubsidyCatalog;
use App\Support\AuditRemarks;
use App\Support\HvccCatalog;
use App\Support\WalkInOverride;
use App\Traits\DecodesBase64Image;
use App\Traits\LogsReportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubsidyController extends Controller
{
    use DecodesBase64Image;
    use LogsReportAudit;
    /**
     * List subsidy programs with beneficiary counts.
     * Technicians only receive Active campaigns for field release.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SubsidyProgram::query()
            ->withCount([
                'beneficiaries',
                'beneficiaries as claimed_count' => fn ($q) => $q->where('status', 'Claimed'),
            ]);

        if ($request->user()?->role === 'technician') {
            $query->where('status', 'Active');
        }

        $programs = $query
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SubsidyProgram $p) => $this->serializeProgram($p));

        return response()->json([
            'status' => 'success',
            'message' => 'Subsidy programs loaded.',
            'data' => $programs,
        ]);
    }

    /**
     * Create a new subsidy program (Draft by default).
     *
     * Catalog-driven programs (seed_class + item_type given) take their unit
     * labels and dual-unit shape from SubsidyCatalog — the client cannot
     * override them. Legacy free-text programs (neither field given) keep
     * the old single-unit behaviour.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_name' => 'required|string|max:255',
            'target_crop' => ['required', Rule::in(['Rice', 'Corn', 'Both', 'HVCC'])],
            'hvcc_commodity' => ['nullable', 'string', 'max:64'],
            'seed_class' => ['nullable', Rule::in(SubsidyCatalog::seedClasses())],
            'item_type' => ['nullable', Rule::in(['seed', 'abono', 'liquid_fertilizer', 'wettable', 'cash'])],
            'max_hectares_limit' => 'required|numeric|min:0.01|max:9999',
            'min_hectares_limit' => 'nullable|numeric|min:0|max:9999|lte:max_hectares_limit',
            'items_per_hectare' => 'required|numeric|min:0.01|max:100000',
            'secondary_items_per_hectare' => 'nullable|numeric|min:0.01|max:100000',
            'status' => ['nullable', Rule::in(['Draft', 'Active', 'Completed'])],
            'unit_of_measurement' => 'nullable|string|max:64',
            'total_quantity' => 'nullable|numeric|min:0|max:1000000',
            'reorder_level' => 'nullable|numeric|min:0|max:1000000',
            'secondary_total_quantity' => 'nullable|numeric|min:0|max:1000000',
            'secondary_reorder_level' => 'nullable|numeric|min:0|max:1000000',
            'target_barangays' => 'nullable|array',
            'target_barangays.*' => Rule::in(OfficialBarangays::names()),
        ]);

        $targetBarangays = $validated['target_barangays'] ?? null;
        if (is_array($targetBarangays) && count($targetBarangays) === 0) {
            $targetBarangays = null;
        }

        $seedClass = $validated['seed_class'] ?? null;
        $itemType = $validated['item_type'] ?? null;
        $isCatalogProgram = $seedClass !== null || $itemType !== null;

        if ($isCatalogProgram && ! SubsidyCatalog::isValidCombo($seedClass, $itemType)) {
            return response()->json([
                'status' => 'error',
                'message' => "{$itemType} is not offered for {$seedClass} in the MAO catalog.",
            ], 422);
        }

        $unit = $isCatalogProgram
            ? SubsidyCatalog::unit($seedClass, $itemType)
            : ($validated['unit_of_measurement'] ?? 'Bags');
        $secondaryUnit = $isCatalogProgram ? SubsidyCatalog::secondaryUnit($seedClass, $itemType) : null;
        $isDualUnit = $secondaryUnit !== null;

        if ($isDualUnit && empty($validated['secondary_items_per_hectare'])) {
            return response()->json([
                'status' => 'error',
                'message' => "This item needs both a {$unit}/ha rate and a {$secondaryUnit}/ha rate.",
            ], 422);
        }

        $totalQuantity = $validated['total_quantity'] ?? 0;
        $secondaryTotalQuantity = $isDualUnit ? ($validated['secondary_total_quantity'] ?? 0) : null;

        $program = SubsidyProgram::create([
            'program_name' => $validated['program_name'],
            'target_crop' => $validated['target_crop'],
            'hvcc_commodity' => ($validated['target_crop'] ?? '') === 'HVCC'
                ? ($validated['hvcc_commodity'] ?? null)
                : null,
            'target_barangays' => $targetBarangays,
            'seed_class' => $seedClass,
            'item_type' => $itemType,
            'max_hectares_limit' => $validated['max_hectares_limit'],
            'min_hectares_limit' => $validated['min_hectares_limit'] ?? 0,
            'items_per_hectare' => $validated['items_per_hectare'],
            'secondary_items_per_hectare' => $isDualUnit ? $validated['secondary_items_per_hectare'] : null,
            'status' => $validated['status'] ?? 'Draft',
            'unit_of_measurement' => $unit ?: 'Bags',
            'secondary_unit' => $secondaryUnit,
            'total_quantity' => $totalQuantity,
            'remaining_quantity' => $totalQuantity,
            'reorder_level' => $validated['reorder_level'] ?? null,
            'secondary_total_quantity' => $secondaryTotalQuantity,
            'secondary_remaining_quantity' => $secondaryTotalQuantity,
            'secondary_reorder_level' => $isDualUnit ? ($validated['secondary_reorder_level'] ?? null) : null,
        ]);

        $this->logReportAudit('subsidy_program.created', $program, [
            'after' => $program->only(['program_name', 'target_crop', 'item_type', 'items_per_hectare', 'status', 'total_quantity']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Subsidy program created.',
            'data' => $program,
        ], 201);
    }

    /**
     * Log an incoming warehouse delivery for one subsidy program (admin only).
     * Adds to both the lifetime total and the currently claimable stock, for
     * both units when the program is dual-unit (e.g. kg + bags).
     */
    public function restock(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity_added' => 'required|numeric|min:0.01|max:1000000',
            'secondary_quantity_added' => 'nullable|numeric|min:0.01|max:1000000',
        ]);

        $remarks = AuditRemarks::require($request, 'A justification is required before logging a warehouse delivery.');
        $before = SubsidyProgram::query()->findOrFail($id)->only(['total_quantity', 'remaining_quantity', 'secondary_total_quantity', 'secondary_remaining_quantity']);

        $program = DB::transaction(function () use ($id, $validated) {
            $program = SubsidyProgram::where('id', $id)->lockForUpdate()->firstOrFail();
            $program->total_quantity += $validated['quantity_added'];
            $program->remaining_quantity += $validated['quantity_added'];

            if (! empty($validated['secondary_quantity_added']) && $program->secondary_unit) {
                $program->secondary_total_quantity = (float) ($program->secondary_total_quantity ?? 0) + $validated['secondary_quantity_added'];
                $program->secondary_remaining_quantity = (float) ($program->secondary_remaining_quantity ?? 0) + $validated['secondary_quantity_added'];
            }

            $program->save();

            return $program;
        });

        $message = "Delivery logged. {$validated['quantity_added']} {$program->unit_of_measurement} added to stock.";
        if (! empty($validated['secondary_quantity_added']) && $program->secondary_unit) {
            $message .= " Plus {$validated['secondary_quantity_added']} {$program->secondary_unit}.";
        }

        $this->logReportAudit('subsidy_program.restocked', $program, [
            'before' => $before,
            'after' => $program->only(['total_quantity', 'remaining_quantity', 'secondary_total_quantity', 'secondary_remaining_quantity']),
            'remarks' => $remarks,
            'quantity_added' => $validated['quantity_added'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $program->fresh(),
        ]);
    }

    /**
     * Update stock-management configuration (admin only): reorder threshold(s)
     * for low-stock warnings. Unit labels can only be retagged on legacy
     * (non-catalog) programs — catalog programs keep their MAO-defined units.
     */
    public function updateConfig(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'unit_of_measurement' => 'nullable|string|max:64',
            'reorder_level' => 'nullable|numeric|min:0|max:1000000',
            'secondary_reorder_level' => 'nullable|numeric|min:0|max:1000000',
        ]);

        $program = SubsidyProgram::query()->findOrFail($id);
        $remarks = AuditRemarks::require($request, 'A justification is required before changing subsidy stock settings.');
        $before = $program->only(['unit_of_measurement', 'reorder_level', 'secondary_reorder_level']);

        $updates = ['reorder_level' => $validated['reorder_level'] ?? null];

        if (! $program->item_type) {
            $updates['unit_of_measurement'] = $validated['unit_of_measurement'] ?? $program->unit_of_measurement;
        }

        if ($program->secondary_unit) {
            $updates['secondary_reorder_level'] = $validated['secondary_reorder_level'] ?? null;
        }

        $program->update($updates);

        $this->logReportAudit('subsidy_program.config_updated', $program, [
            'before' => $before,
            'after' => $program->fresh()->only(array_keys($before)),
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stock settings updated.',
            'data' => $program->fresh(),
        ]);
    }

    /**
     * Set campaign status (Draft / Active / Completed). Completed freezes claims
     * and masterlist regeneration; records stay as history.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['Draft', 'Active', 'Completed'])],
        ]);

        $program = SubsidyProgram::query()->findOrFail($id);
        $before = $program->only(['status']);
        $program->update(['status' => $validated['status']]);

        $this->logReportAudit('subsidy_program.status_updated', $program, [
            'before' => $before,
            'after' => $program->only(['status']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Program marked {$validated['status']}.",
            'data' => $program->fresh(),
        ]);
    }

    /**
     * Generate eligible beneficiaries from registered plots / active plantings.
     * Farmers are ranked (PWD/senior, then approved calamity damage or outbreak,
     * then smallholders) and added as Pending until warehouse stock is exhausted.
     * Everyone past that cutoff is stored as Waitlisted so a later restock can
     * promote them. Dual-unit items allocate each unit from its own per-hectare rate.
     */
    public function generateMasterlist(string $id): JsonResponse
    {
        $program = SubsidyProgram::query()->findOrFail($id);

        if ($program->status === 'Completed') {
            return response()->json([
                'status' => 'error',
                'message' => 'A completed subsidy program cannot regenerate its masterlist.',
            ], 409);
        }

        [$plotArea, $plantArea] = $this->cropAreaSubqueries((string) $program->target_crop, $program->hvcc_commodity);

        $skippedNoRsbsaQuery = DB::table('farmers')
            ->leftJoinSub($plotArea, 'plots', fn ($join) => $join->on('plots.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($plantArea, 'planted', fn ($join) => $join->on('planted.farmer_id', '=', 'farmers.id'))
            ->whereNull('farmers.deleted_at')
            ->where(function ($q) {
                $q->whereNull('farmers.rsbsa_no')->orWhere('farmers.rsbsa_no', '');
            })
            ->where(function ($q) {
                $q->whereNotNull('plots.area')->orWhereNotNull('planted.area');
            });
        $this->applyBarangayScope($skippedNoRsbsaQuery, $program);
        $skippedNoRsbsa = (int) $skippedNoRsbsaQuery->count();

        $eligibleFarmers = $this->eligibleFarmerQuery($program, [
            'exclude_history' => true,
        ])->get();

        $sorted = $eligibleFarmers->sort(function ($a, $b) {
            $tier = $this->tierSortKey($a) <=> $this->tierSortKey($b);
            if ($tier !== 0) {
                return $tier;
            }
            $damage = ((float) ($b->damage_percentage ?? 0)) <=> ((float) ($a->damage_percentage ?? 0));
            if ($damage !== 0) {
                return $damage;
            }
            $area = ((float) $a->farm_area) <=> ((float) $b->farm_area);
            if ($area !== 0) {
                return $area;
            }

            return strcmp((string) ($a->surname ?? ''), (string) ($b->surname ?? ''));
        })->values();

        $persist = $this->persistAllocatedRows($program, $sorted, 'auto');

        $masterlistCountQuery = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id);
        SubsidyBeneficiary::applyNotDeleted($masterlistCountQuery);
        $masterlistCount = $masterlistCountQuery->count();

        $generatedCount = $persist['generated_count'];
        $updatedCount = $persist['updated_count'];
        $waitlistedCount = $persist['waitlisted_count'];
        $eligibleCount = $persist['eligible_count'];

        $message = "{$generatedCount} new beneficiaries added to the masterlist.";
        if ($updatedCount > 0) {
            $message .= " {$updatedCount} pending allocation(s) recalculated.";
        }
        if ($waitlistedCount > 0) {
            $message .= " {$waitlistedCount} farmer(s) waitlisted because warehouse stock was exhausted.";
        }
        if ($generatedCount === 0 && $updatedCount === 0 && $eligibleCount === 0) {
            $message = $skippedNoRsbsa > 0
                ? "No eligible farmers found. {$skippedNoRsbsa} matching farmer(s) were skipped because they have no RSBSA number."
                : 'No eligible farmers found. Matching farmers need an RSBSA number plus a Rice/Corn farm plot or an active planting log.';
        }

        $this->logReportAudit('subsidy_program.masterlist_generated', $program, [
            'after' => [
                'eligible_count' => $eligibleCount,
                'generated_count' => $generatedCount,
                'updated_count' => $updatedCount,
                'waitlisted_count' => $waitlistedCount,
                'skipped_no_rsbsa' => $skippedNoRsbsa,
                'masterlist_count' => $masterlistCount,
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'program_id' => $program->id,
                'eligible_count' => $eligibleCount,
                'generated_count' => $generatedCount,
                'updated_count' => $updatedCount,
                'waitlisted_count' => $waitlistedCount,
                'skipped_no_rsbsa' => $skippedNoRsbsa,
                'masterlist_count' => $masterlistCount,
            ],
        ]);
    }

    /**
     * Faceted RSBSA search for manual masterlist picking. Allocations are
     * previews only — nothing is written until manualSelect().
     */
    public function manualFilter(Request $request, string $id): JsonResponse
    {
        $program = SubsidyProgram::query()->findOrFail($id);

        $validated = $request->validate([
            'barangays' => 'nullable|array',
            'barangays.*' => 'string|max:128',
            'commodity' => ['nullable', 'string', 'max:64'],
            'min_ha' => 'nullable|numeric|min:0|max:9999',
            'max_ha' => 'nullable|numeric|min:0|max:9999',
            'rdana_validated' => 'nullable|boolean',
            'rdana_months' => 'nullable|integer|min:1|max:120',
            'rdana_min_severity' => 'nullable|numeric|min:0|max:100',
            'pest_outbreak' => 'nullable|boolean',
            'social_priority' => 'nullable|boolean',
            'exclude_history' => 'nullable|boolean',
            'search' => 'nullable|string|max:128',
        ]);

        $excludeHistory = array_key_exists('exclude_history', $validated)
            ? (bool) $validated['exclude_history']
            : true;

        $farmers = $this->eligibleFarmerQuery($program, [
            'exclude_history' => $excludeHistory,
            'barangays' => $validated['barangays'] ?? null,
            'commodity' => $validated['commodity'] ?? null,
            'min_ha' => isset($validated['min_ha']) ? (float) $validated['min_ha'] : null,
            'max_ha' => isset($validated['max_ha']) ? (float) $validated['max_ha'] : null,
            'rdana_validated' => (bool) ($validated['rdana_validated'] ?? false),
            'rdana_months' => isset($validated['rdana_months']) ? (int) $validated['rdana_months'] : null,
            'rdana_min_severity' => isset($validated['rdana_min_severity']) ? (float) $validated['rdana_min_severity'] : null,
            'pest_outbreak' => (bool) ($validated['pest_outbreak'] ?? false),
            'social_priority' => (bool) ($validated['social_priority'] ?? false),
            'search' => $validated['search'] ?? null,
            'include_unregistered' => true,
        ])->orderBy('farmers.surname')->orderBy('farmers.first_name')->get();

        $onMasterlist = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id)
            ->whereIn('farmer_rsbsa_no', $farmers->pluck('rsbsa_no')->filter()->all());
        SubsidyBeneficiary::applyNotDeleted($onMasterlist);
        $existing = $onMasterlist->get(['farmer_rsbsa_no', 'status'])->keyBy('farmer_rsbsa_no');

        $rows = $farmers->map(function ($farmer) use ($program, $existing) {
            $allocation = $this->allocationForArea($program, (float) $farmer->farm_area);
            if ($allocation === null) {
                return null;
            }

            $current = $existing->get($farmer->rsbsa_no);

            return [
                'farmer_id' => $farmer->id,
                'rsbsa_no' => $farmer->rsbsa_no,
                'is_temporary' => (bool) ($farmer->is_temporary ?? false),
                'last_name' => $farmer->surname,
                'first_name' => $farmer->first_name,
                'middle_name' => $farmer->middle_name,
                'barangay' => $farmer->permanent_brgy,
                'mobile_number' => $farmer->mobile_number,
                'farm_area' => round((float) $farmer->farm_area, 4),
                'is_pwd' => (bool) $farmer->is_pwd,
                'is_senior' => $this->isSeniorCitizen($farmer->birthdate),
                'damage_percentage' => $farmer->damage_percentage !== null ? (float) $farmer->damage_percentage : null,
                'is_outbreak' => (bool) $farmer->is_outbreak,
                'priority_tier' => $this->priorityTierForFarmer($farmer, (float) $farmer->farm_area),
                'calculated_allocation' => $allocation['primary'],
                'calculated_allocation_secondary' => $allocation['secondary'],
                'already_on_masterlist' => $current !== null,
                'masterlist_status' => $current->status ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Eligible farmers loaded.',
            'data' => [
                'program' => $this->serializeProgram($program),
                'count' => $rows->count(),
                'farmers' => $rows,
            ],
        ]);
    }

    /**
     * Hand-picked farmers, in the order submitted, are allocated until stock
     * runs out. Later picks are Waitlisted. Claimed rows are left unchanged.
     */
    public function manualSelect(Request $request, string $id): JsonResponse
    {
        $program = SubsidyProgram::query()->findOrFail($id);

        if ($program->status === 'Completed') {
            return response()->json([
                'status' => 'error',
                'message' => 'A completed subsidy program cannot change its masterlist.',
            ], 409);
        }

        $validated = $request->validate([
            'farmer_rsbsa_nos' => 'nullable|array|max:5000',
            'farmer_rsbsa_nos.*' => 'string|max:64',
            'farmer_ids' => 'nullable|array|max:5000',
            'farmer_ids.*' => 'uuid',
        ]);

        $ordered = array_values(array_unique(array_map(
            fn ($rsbsa) => trim((string) $rsbsa),
            $validated['farmer_rsbsa_nos'] ?? []
        )));
        $ordered = array_values(array_filter($ordered, fn ($rsbsa) => $rsbsa !== ''));
        $farmerIds = array_values(array_unique($validated['farmer_ids'] ?? []));

        if ($ordered === [] && $farmerIds === []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Select at least one farmer.',
            ], 422);
        }

        $farmers = $ordered === []
            ? collect()
            : $this->eligibleFarmerQuery($program, [
                'exclude_history' => false,
                'rsbsa_nos' => $ordered,
            ])->get()->keyBy('rsbsa_no');

        $picked = collect($ordered)
            ->map(fn ($rsbsa) => $farmers->get($rsbsa))
            ->filter()
            ->values();

        if ($farmerIds !== []) {
            $walkIns = $this->eligibleFarmerQuery($program, [
                'exclude_history' => false,
                'farmer_ids' => $farmerIds,
                'include_unregistered' => true,
            ])->get();
            $picked = $picked->concat($walkIns)->unique('id')->values();
        }

        $persist = $this->persistAllocatedRows($program, $picked, 'manual');

        $this->logReportAudit('subsidy_program.manual_selected', $program, [
            'after' => [
                'requested_count' => count($ordered) + count($farmerIds),
                'added_count' => $persist['pending_count'],
                'waitlisted_count' => $persist['waitlisted_count'],
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "{$persist['pending_count']} farmer(s) set to Pending. {$persist['waitlisted_count']} waitlisted once stock ran out.",
            'data' => [
                'added_count' => $persist['pending_count'],
                'waitlisted_count' => $persist['waitlisted_count'],
                'skipped_count' => max(0, count($ordered) + count($farmerIds) - $picked->count()),
            ],
        ]);
    }

    /**
     * Return a compact, spreadsheet-ready masterlist for one program.
     */
    public function masterlist(string $id): JsonResponse
    {
        $program = SubsidyProgram::query()->findOrFail($id);

        [$plotArea, $plantArea] = $this->cropAreaSubqueries((string) $program->target_crop, $program->hvcc_commodity);

        $masterlist = DB::table('tbl_subsidy_beneficiaries as beneficiaries')
            ->join('farmers', 'farmers.id', '=', 'beneficiaries.farmer_id')
            ->leftJoinSub($plotArea, 'plots', fn ($join) => $join->on('plots.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($plantArea, 'planted', fn ($join) => $join->on('planted.farmer_id', '=', 'farmers.id'))
            ->where('beneficiaries.program_id', $program->id)
            ->whereNull('farmers.deleted_at');
        SubsidyBeneficiary::applyNotDeleted($masterlist, 'beneficiaries.deleted_at');
        $masterlist = $masterlist
            ->orderByRaw("CASE beneficiaries.status WHEN 'Pending' THEN 0 WHEN 'Waitlisted' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN beneficiaries.priority_tier IS NULL THEN 99 ELSE beneficiaries.priority_tier END')
            ->orderBy('farmers.surname')
            ->orderBy('farmers.first_name')
            ->select([
                'beneficiaries.id as beneficiary_id',
                'farmers.id as farmer_id',
                'beneficiaries.farmer_rsbsa_no as rsbsa_no',
                'farmers.surname as last_name',
                'farmers.first_name',
                'farmers.middle_name',
                'farmers.permanent_brgy as barangay',
                'farmers.mobile_number',
                'farmers.is_pwd',
                'farmers.is_temporary',
                'farmers.registration_type',
                'farmers.birthdate',
                'beneficiaries.is_walkin',
                'beneficiaries.override_reason_code',
                'beneficiaries.calculated_allocation',
                'beneficiaries.calculated_allocation_secondary',
                'beneficiaries.status',
                'beneficiaries.priority_tier',
                'beneficiaries.selection_mode',
            ])
            ->selectRaw('ROUND('.$this->farmAreaSql().', 4) as farm_area')
            ->get()
            ->map(function ($row) {
                $row->is_pwd = (bool) $row->is_pwd;
                $row->is_temporary = (bool) $row->is_temporary;
                $row->is_walkin = (bool) $row->is_walkin;
                $row->is_senior = $this->isSeniorCitizen($row->birthdate);
                unset($row->birthdate);

                return $row;
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Subsidy masterlist loaded.',
            'data' => [
                'program' => $this->serializeProgram($program),
                'count' => $masterlist->count(),
                'masterlist' => $masterlist,
            ],
        ]);
    }

    /**
     * Mark one beneficiary as Claimed and deduct their allocation from the
     * program's warehouse stock (DA 6-step distribution: release = stock out).
     * Deducts both units in one transaction when the item is dual-unit.
     */
    public function claimBeneficiary(Request $request, string $id, string $beneficiaryId): JsonResponse
    {
        $validated = $request->validate([
            'photo_proof_base64' => 'nullable|string',
            'override_password' => 'nullable|string',
            'override_reason' => 'nullable|string|max:500',
            'override_reason_code' => 'nullable|string|max:32',
            'override_justification' => 'nullable|string|max:500',
        ]);

        $result = DB::transaction(function () use ($id, $beneficiaryId, $validated) {
            $program = SubsidyProgram::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($program->status !== 'Active') {
                return ['error' => 'This subsidy program is not active. Claims are frozen.', 'code' => 400];
            }

            $beneficiaryQuery = DB::table('tbl_subsidy_beneficiaries')
                ->where('id', $beneficiaryId)
                ->where('program_id', $id);
            SubsidyBeneficiary::applyNotDeleted($beneficiaryQuery);
            $beneficiary = $beneficiaryQuery->first();

            if (!$beneficiary) {
                return ['error' => 'Beneficiary not found on this program.', 'code' => 404];
            }

            if ($beneficiary->status === 'Claimed') {
                return ['error' => 'This beneficiary has already claimed their allocation.', 'code' => 409];
            }

            if ($beneficiary->status === 'Waitlisted') {
                return ['error' => 'This farmer is still waitlisted pending stock and cannot claim yet.', 'code' => 409];
            }

            $farmer = $beneficiary->farmer_id
                ? Farmer::query()->find($beneficiary->farmer_id)
                : Farmer::query()->where('rsbsa_no', $beneficiary->farmer_rsbsa_no)->first();
            $override = null;
            if ($farmer && ($farmer->is_temporary || ! $farmer->rsbsa_no)) {
                $override = WalkInOverride::resolve($validated, Auth::id(), true);
                if (isset($override['error'])) {
                    return [
                        'error' => $override['error'],
                        'code' => $override['status'],
                        'error_code' => 'ADMIN_OVERRIDE_REQUIRED',
                    ];
                }
            }

            $allocation = $this->cashCappedAllocation($program, (int) $beneficiary->calculated_allocation);
            $allocationSecondary = $beneficiary->calculated_allocation_secondary !== null
                ? (int) $beneficiary->calculated_allocation_secondary
                : null;

            $shortfall = $this->stockShortfallMessage($program, $allocation, $allocationSecondary);
            if ($shortfall) {
                return ['error' => $shortfall, 'code' => 409];
            }

            $program->remaining_quantity -= $allocation;
            if ($allocationSecondary !== null && $program->secondary_unit) {
                $program->secondary_remaining_quantity = (float) ($program->secondary_remaining_quantity ?? 0) - $allocationSecondary;
            }
            $program->save();

            $photoPath = $this->storeBase64Image($validated['photo_proof_base64'] ?? null, 'subsidy-claims');

            $claimUpdate = [
                'status' => 'Claimed',
                'claimed_at' => now(),
                'claimed_by' => Auth::id(),
                'photo_proof_path' => $photoPath,
                'updated_at' => now(),
            ];
            if (is_array($override)) {
                $claimUpdate['override_by_admin_id'] = $override['overridden_by'];
                $claimUpdate['override_timestamp'] = now();
                $claimUpdate['override_justification'] = $override['reason'];
                $claimUpdate['override_reason_code'] = $override['reason_code'];
                $claimUpdate['is_walkin'] = true;
                if ($farmer) {
                    $claimUpdate['farmer_id'] = $farmer->id;
                }
            }
            DB::table('tbl_subsidy_beneficiaries')
                ->where('id', $beneficiaryId)
                ->update($claimUpdate);

            return ['program' => $program->fresh()];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'code' => $result['error_code'] ?? null,
                'message' => $result['error'],
            ], $result['code']);
        }

        $claimed = SubsidyBeneficiary::find($beneficiaryId);
        $this->logReportAudit('subsidy_beneficiary.claimed', $claimed, [
            'after' => array_filter([
                'status' => 'Claimed',
                'program_id' => $id,
                'override_reason_code' => $claimed?->override_reason_code,
                'override_by_admin_id' => $claimed?->override_by_admin_id,
            ]),
            'record_code' => $beneficiaryId,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Beneficiary marked as Claimed. Stock updated.',
            'data' => [
                'program' => $result['program'],
            ],
        ]);
    }

    /**
     * Claimed subsidy rows are immutable; use void + re-claim instead of editing.
     */
    public function updateBeneficiaryClaim(Request $request, string $beneficiaryId): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Claimed subsidy records cannot be edited. Void the claim if a correction is required.',
        ], 403);
    }

    /**
     * Void a claimed beneficiary and restock program inventory.
     */
    public function voidBeneficiaryClaim(Request $request, string $beneficiaryId): JsonResponse
    {
        $remarks = AuditRemarks::require($request, 'A justification is required before voiding a subsidy claim.');
        $result = DB::transaction(function () use ($beneficiaryId) {
            $beneficiary = SubsidyBeneficiary::where('status', 'Claimed')->lockForUpdate()->find($beneficiaryId);
            if (! $beneficiary) {
                return ['error' => 'Claimed beneficiary not found.', 'code' => 404];
            }

            $before = $beneficiary->only([
                'status',
                'claimed_at',
                'claimed_by',
                'photo_proof_path',
                'calculated_allocation',
                'calculated_allocation_secondary',
            ]);
            if (isset($before['claimed_at']) && $before['claimed_at'] instanceof \DateTimeInterface) {
                $before['claimed_at'] = $before['claimed_at']->format(\DateTimeInterface::ATOM);
            }

            $program = SubsidyProgram::where('id', $beneficiary->program_id)->lockForUpdate()->firstOrFail();
            $allocation = $this->cashCappedAllocation($program, (int) $beneficiary->calculated_allocation);
            $allocationSecondary = $beneficiary->calculated_allocation_secondary !== null
                ? (int) $beneficiary->calculated_allocation_secondary
                : null;

            $remainingBefore = (float) $program->remaining_quantity;
            $secondaryBefore = $program->secondary_unit !== null
                ? (float) ($program->secondary_remaining_quantity ?? 0)
                : null;

            $program->remaining_quantity += $allocation;
            if ($allocationSecondary !== null && $program->secondary_unit) {
                $program->secondary_remaining_quantity = (float) ($program->secondary_remaining_quantity ?? 0) + $allocationSecondary;
            }
            $program->save();

            $beneficiary->update([
                'status' => 'Pending',
                'claimed_at' => null,
                'claimed_by' => null,
                'photo_proof_path' => null,
            ]);

            $fresh = $beneficiary->fresh();
            $after = [
                'status' => $fresh->status,
                'claimed_at' => null,
                'claimed_by' => null,
                'photo_proof_path' => null,
                'calculated_allocation' => $fresh->calculated_allocation,
                'calculated_allocation_secondary' => $fresh->calculated_allocation_secondary,
                'program_remaining_quantity' => (float) $program->remaining_quantity,
                'restocked_primary' => $allocation,
            ];
            if ($secondaryBefore !== null) {
                $after['program_secondary_remaining_quantity'] = (float) ($program->secondary_remaining_quantity ?? 0);
                $after['restocked_secondary'] = $allocationSecondary;
            }

            return [
                'beneficiary' => $fresh,
                'program' => $program->fresh(),
                'before' => $before + [
                    'program_remaining_quantity' => $remainingBefore,
                    'program_secondary_remaining_quantity' => $secondaryBefore,
                ],
                'after' => $after,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
            ], $result['code']);
        }

        $this->logReportAudit('subsidy_beneficiary.voided', $result['beneficiary'], [
            'program_id' => $result['beneficiary']->program_id,
            'restocked' => true,
            'before' => $result['before'],
            'after' => $result['after'],
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Claim voided and stock restored.',
            'data' => ['program' => $result['program']],
        ]);
    }

    /**
     * Field eligibility check: farmer must be on the Active masterlist.
     */
    public function verifyFarmer(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'farmer_id' => 'nullable|uuid|exists:farmers,id',
            'rsbsa_no' => 'nullable|string|max:64',
        ]);

        if (empty($validated['farmer_id']) && empty($validated['rsbsa_no'])) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'Provide a farmer ID or RSBSA number.',
            ], 422);
        }

        $program = SubsidyProgram::query()->findOrFail($id);
        if ($program->status !== 'Active') {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This subsidy program is not active.',
            ], 400);
        }

        $farmer = $this->resolveFarmer($validated['farmer_id'] ?? null, $validated['rsbsa_no'] ?? null);
        if (! $farmer) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'No registered farmer matches that ID / RSBSA.',
            ], 404);
        }

        $requiresOverride = (bool) $farmer->is_temporary || ! $farmer->rsbsa_no;

        $beneficiaryQuery = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id)
            ->where(function ($q) use ($farmer) {
                $q->where('farmer_id', $farmer->id);
                if ($farmer->rsbsa_no) {
                    $q->orWhere('farmer_rsbsa_no', $farmer->rsbsa_no);
                }
            });
        SubsidyBeneficiary::applyNotDeleted($beneficiaryQuery);
        $beneficiary = $beneficiaryQuery->first();

        if (! $beneficiary) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This farmer is not on the masterlist for this program.',
            ], 404);
        }

        if ($beneficiary->status === 'Claimed') {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This farmer has already claimed their allocation for this program.',
                'data' => [
                    'claimed_at' => $beneficiary->claimed_at,
                ],
            ], 409);
        }

        if ($beneficiary->status === 'Waitlisted') {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This farmer is still waitlisted pending stock and cannot claim yet.',
            ], 409);
        }

        $allocation = $this->cashCappedAllocation($program, (int) $beneficiary->calculated_allocation);
        $allocationSecondary = $beneficiary->calculated_allocation_secondary !== null
            ? (int) $beneficiary->calculated_allocation_secondary
            : null;

        $shortfall = $this->stockShortfallMessage($program, $allocation, $allocationSecondary);
        if ($shortfall) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => $shortfall,
            ], 409);
        }

        $primaryPlot = $farmer->farmPlots()->first();
        $totalFarmSize = $this->cropAreaForFarmer($farmer->id, (string) $program->target_crop, $program->hvcc_commodity);
        $minHa = (float) ($program->min_hectares_limit ?? 0);
        if ($minHa > 0 && $totalFarmSize + 0.0000001 < $minHa) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => "This farmer's {$program->target_crop} area ({$totalFarmSize} ha) is below the program minimum of {$minHa} ha.",
            ], 400);
        }
        $cap = (float) ($program->max_hectares_limit ?? $totalFarmSize);
        $eligibleSize = $cap > 0 ? min($totalFarmSize, $cap) : $totalFarmSize;

        return response()->json([
            'status' => 'success',
            'eligible' => true,
            'message' => 'Verification passed. Farmer is eligible to claim.',
            'data' => [
                'farmer_id' => $farmer->id,
                'program_id' => $program->id,
                'beneficiary_id' => $beneficiary->id,
                'farmer_name' => trim($farmer->surname.', '.$farmer->first_name.' '.$farmer->middle_name),
                'mobile_number' => $farmer->mobile_number,
                'item_released' => $program->program_name,
                'seed_class' => $program->seed_class,
                'item_type' => $program->item_type,
                'unit' => $program->unit_of_measurement,
                'total_farm_size' => $totalFarmSize,
                'eligible_size' => $eligibleSize,
                'quantity' => $allocation,
                'inventory_remaining' => (float) $program->remaining_quantity,
                'unit_secondary' => $program->secondary_unit,
                'quantity_secondary' => $allocationSecondary,
                'inventory_remaining_secondary' => $program->secondary_unit
                    ? (float) ($program->secondary_remaining_quantity ?? 0)
                    : null,
                'plot_lat' => $primaryPlot?->latitude,
                'plot_long' => $primaryPlot?->longitude,
                'source' => 'subsidy',
                'requires_override' => $requiresOverride,
                'is_temporary' => (bool) $farmer->is_temporary,
            ],
        ]);
    }

    /**
     * Technician / admin field claim by farmer (RSBSA masterlist).
     */
    public function claimForFarmer(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'farmer_id' => 'nullable|uuid|exists:farmers,id',
            'rsbsa_no' => 'nullable|string|max:64',
            'beneficiary_id' => 'nullable|uuid',
            'photo_proof_base64' => 'nullable|string',
            'override_password' => 'nullable|string',
            'override_reason' => 'nullable|string|max:500',
            'override_reason_code' => 'nullable|string|max:32',
            'override_justification' => 'nullable|string|max:500',
        ]);

        $result = $this->executeClaim($id, $validated, $request->user()?->id);

        if ($result['outcome'] !== 'synced') {
            return response()->json([
                'status' => 'error',
                'code' => $result['error_code'] ?? null,
                'message' => $result['message'],
            ], $result['code'] ?? 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
            'data' => $result['data'],
        ]);
    }

    /**
     * Core claim logic shared by the live `claim-farmer` endpoint and the
     * offline `/sync/bulk` queue. Re-validates eligibility/stock at execution
     * time so a stale offline claim is rejected — not silently accepted — if
     * the farmer was already claimed or stock ran out in the meantime.
     *
     * @param  array{farmer_id?: ?string, rsbsa_no?: ?string, beneficiary_id?: ?string, photo_proof_base64?: ?string, claimed_at?: ?string}  $item
     * @return array{outcome: 'synced'|'duplicate'|'failed', message: string, code?: int, data?: array}
     */
    public function executeClaim(string $programId, array $item, ?string $technicianId = null): array
    {
        if (empty($item['farmer_id']) && empty($item['rsbsa_no']) && empty($item['beneficiary_id'])) {
            return ['outcome' => 'failed', 'code' => 422, 'message' => 'Provide a farmer ID, RSBSA number, or beneficiary ID.'];
        }

        $farmer = $this->resolveFarmer($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);

        $result = DB::transaction(function () use ($programId, $item, $farmer, $technicianId) {
            $program = SubsidyProgram::where('id', $programId)->lockForUpdate()->first();

            if (! $program) {
                return ['error' => 'Subsidy program not found.', 'code' => 404, 'outcome' => 'failed'];
            }

            if ($program->status !== 'Active') {
                return ['error' => 'This subsidy program is not active.', 'code' => 400, 'outcome' => 'failed'];
            }

            $beneficiaryQuery = DB::table('tbl_subsidy_beneficiaries')
                ->where('program_id', $programId);
            SubsidyBeneficiary::applyNotDeleted($beneficiaryQuery);

            if (! empty($item['beneficiary_id'])) {
                $beneficiaryQuery->where('id', $item['beneficiary_id']);
            } elseif ($farmer) {
                $beneficiaryQuery->where(function ($q) use ($farmer) {
                    $q->where('farmer_id', $farmer->id);
                    if ($farmer->rsbsa_no) {
                        $q->orWhere('farmer_rsbsa_no', $farmer->rsbsa_no);
                    }
                });
            } else {
                return ['error' => 'Farmer is not on this program masterlist.', 'code' => 404, 'outcome' => 'failed'];
            }

            $beneficiary = $beneficiaryQuery->lockForUpdate()->first();

            if (! $beneficiary) {
                return ['error' => 'This farmer is not on the masterlist for this program.', 'code' => 404, 'outcome' => 'failed'];
            }

            if ($beneficiary->status === 'Claimed') {
                // Idempotent: an offline device may replay a claim it already
                // succeeded at online. Treat as resolved, not an error.
                return ['error' => 'This farmer has already claimed their allocation for this program.', 'code' => 409, 'outcome' => 'duplicate'];
            }

            if ($beneficiary->status === 'Waitlisted') {
                return ['error' => 'This farmer is still waitlisted pending stock and cannot claim yet.', 'code' => 409, 'outcome' => 'failed'];
            }

            $claimFarmer = $farmer;
            if (! $claimFarmer && ! empty($beneficiary->farmer_id)) {
                $claimFarmer = Farmer::query()->find($beneficiary->farmer_id);
            }
            $override = null;
            if ($claimFarmer && ($claimFarmer->is_temporary || ! $claimFarmer->rsbsa_no)) {
                $override = WalkInOverride::resolve($item, $technicianId ?? Auth::id(), true);
                if (isset($override['error'])) {
                    return [
                        'error' => $override['error'],
                        'code' => $override['status'],
                        'outcome' => 'failed',
                        'error_code' => 'ADMIN_OVERRIDE_REQUIRED',
                    ];
                }
            }

            $allocation = $this->cashCappedAllocation($program, (int) $beneficiary->calculated_allocation);
            $allocationSecondary = $beneficiary->calculated_allocation_secondary !== null
                ? (int) $beneficiary->calculated_allocation_secondary
                : null;

            $shortfall = $this->stockShortfallMessage($program, $allocation, $allocationSecondary);
            if ($shortfall) {
                return ['error' => $shortfall, 'code' => 409, 'outcome' => 'failed'];
            }

            $program->remaining_quantity -= $allocation;
            if ($allocationSecondary !== null && $program->secondary_unit) {
                $program->secondary_remaining_quantity = (float) ($program->secondary_remaining_quantity ?? 0) - $allocationSecondary;
            }
            $program->save();

            $photoPath = $this->storeBase64Image($item['photo_proof_base64'] ?? null, 'subsidy-claims');

            $claimUpdate = [
                'status' => 'Claimed',
                'claimed_at' => $this->parseClaimedAt($item['claimed_at'] ?? null),
                'claimed_by' => $technicianId ?? Auth::id(),
                'photo_proof_path' => $photoPath,
                'updated_at' => now(),
            ];
            if (is_array($override)) {
                $claimUpdate['override_by_admin_id'] = $override['overridden_by'];
                $claimUpdate['override_timestamp'] = now();
                $claimUpdate['override_justification'] = $override['reason'];
                $claimUpdate['override_reason_code'] = $override['reason_code'];
                $claimUpdate['is_walkin'] = true;
            }
            DB::table('tbl_subsidy_beneficiaries')
                ->where('id', $beneficiary->id)
                ->update($claimUpdate);

            return [
                'program' => $program->fresh(),
                'beneficiary' => $beneficiary,
                'farmer' => $farmer,
            ];
        });

        if (isset($result['error'])) {
            return [
                'outcome' => $result['outcome'],
                'code' => $result['code'],
                'message' => $result['error'],
                'error_code' => $result['error_code'] ?? null,
            ];
        }

        $farmerName = $result['farmer']
            ? trim($result['farmer']->surname.', '.$result['farmer']->first_name)
            : ($result['beneficiary']->farmer_rsbsa_no ?? 'Farmer');

        $beneficiaryModel = SubsidyBeneficiary::find($result['beneficiary']->id ?? null);
        $this->logReportAudit('subsidy_beneficiary.claimed', $beneficiaryModel, [
            'after' => [
                'status' => 'Claimed',
                'program_id' => $programId,
                'farmer_rsbsa_no' => $result['beneficiary']->farmer_rsbsa_no ?? null,
            ],
            'record_code' => $result['beneficiary']->farmer_rsbsa_no ?? ($result['beneficiary']->id ?? null),
        ]);

        return [
            'outcome' => 'synced',
            'message' => 'Subsidy released and stock updated.',
            'data' => [
                'farmer_name' => $farmerName,
                'quantity_dispensed' => (int) $result['beneficiary']->calculated_allocation,
                'unit' => $result['program']->unit_of_measurement,
                'inventory_remaining' => (float) $result['program']->remaining_quantity,
                'quantity_dispensed_secondary' => $result['beneficiary']->calculated_allocation_secondary !== null
                    ? (int) $result['beneficiary']->calculated_allocation_secondary
                    : null,
                'unit_secondary' => $result['program']->secondary_unit,
                'inventory_remaining_secondary' => $result['program']->secondary_unit
                    ? (float) ($result['program']->secondary_remaining_quantity ?? 0)
                    : null,
                'program' => $result['program'],
            ],
        ];
    }

    private function resolveFarmer(?string $farmerId, ?string $rsbsaNo): ?Farmer
    {
        if ($farmerId) {
            return Farmer::with('farmPlots')->find($farmerId);
        }

        $rsbsa = trim((string) $rsbsaNo);
        if ($rsbsa === '') {
            return null;
        }

        return Farmer::with('farmPlots')->where('rsbsa_no', $rsbsa)->first();
    }

    /**
     * Shared index/masterlist program payload: seed class, item type, both
     * units, both stock buckets, and a combined low-stock flag.
     */
    private function serializeProgram(SubsidyProgram $p): array
    {
        return [
            'id' => $p->id,
            'program_name' => $p->program_name,
            'target_crop' => $p->target_crop,
            'hvcc_commodity' => $p->hvcc_commodity,
            'target_barangays' => $p->target_barangays,
            'seed_class' => $p->seed_class,
            'item_type' => $p->item_type,
            'max_hectares_limit' => (float) $p->max_hectares_limit,
            'min_hectares_limit' => (float) ($p->min_hectares_limit ?? 0),
            'items_per_hectare' => (float) $p->items_per_hectare,
            'secondary_items_per_hectare' => $p->secondary_items_per_hectare !== null ? (float) $p->secondary_items_per_hectare : null,
            'status' => $p->status,
            'unit_of_measurement' => $p->unit_of_measurement,
            'secondary_unit' => $p->secondary_unit,
            'total_quantity' => (float) $p->total_quantity,
            'remaining_quantity' => (float) $p->remaining_quantity,
            'reorder_level' => $p->reorder_level !== null ? (float) $p->reorder_level : null,
            'secondary_total_quantity' => $p->secondary_total_quantity !== null ? (float) $p->secondary_total_quantity : null,
            'secondary_remaining_quantity' => $p->secondary_remaining_quantity !== null ? (float) $p->secondary_remaining_quantity : null,
            'secondary_reorder_level' => $p->secondary_reorder_level !== null ? (float) $p->secondary_reorder_level : null,
            'is_low_stock' => $this->isLowStock($p),
            'beneficiaries_count' => (int) ($p->beneficiaries_count ?? 0),
            'claimed_count' => (int) ($p->claimed_count ?? 0),
            'created_at' => optional($p->created_at)->toIso8601String(),
        ];
    }

    /**
     * Low stock if either unit bucket is at/below its own reorder level.
     */
    private function isLowStock(SubsidyProgram $program): bool
    {
        $primaryLow = $program->reorder_level !== null
            && (float) $program->remaining_quantity <= (float) $program->reorder_level;

        $secondaryLow = $program->secondary_unit !== null
            && $program->secondary_reorder_level !== null
            && (float) ($program->secondary_remaining_quantity ?? 0) <= (float) $program->secondary_reorder_level;

        return $primaryLow || $secondaryLow;
    }

    /**
     * Null when both stock buckets can cover the allocation; otherwise the
     * user-facing shortage message for whichever bucket is short.
     */
    private function stockShortfallMessage(SubsidyProgram $program, int $allocation, ?int $allocationSecondary): ?string
    {
        if ((float) $program->remaining_quantity < $allocation) {
            return "Insufficient stock. Only {$program->remaining_quantity} {$program->unit_of_measurement} remaining, but this farmer is allocated {$allocation}. Log a delivery first.";
        }

        if ($allocationSecondary !== null && $program->secondary_unit) {
            $secondaryRemaining = (float) ($program->secondary_remaining_quantity ?? 0);
            if ($secondaryRemaining < $allocationSecondary) {
                return "Insufficient stock. Only {$secondaryRemaining} {$program->secondary_unit} remaining, but this farmer is allocated {$allocationSecondary}. Log a delivery first.";
            }
        }

        return null;
    }

    /**
     * Eligible RSBSA farmers for a program, with plot-first area plus damage
     * and outbreak flags used by priority ranking and the manual filter drawer.
     *
     * @param  array{
     *     exclude_history?: bool,
     *     barangays?: ?array,
     *     commodity?: ?string,
     *     min_ha?: ?float,
     *     max_ha?: ?float,
     *     rdana_validated?: bool,
     *     rdana_months?: ?int,
     *     rdana_min_severity?: ?float,
     *     pest_outbreak?: bool,
     *     social_priority?: bool,
     *     search?: ?string,
     *     rsbsa_nos?: ?array
     * }  $filters
     */
    private function eligibleFarmerQuery(SubsidyProgram $program, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $crop = ! empty($filters['commodity']) ? (string) $filters['commodity'] : (string) $program->target_crop;
        $hvccCommodity = $program->hvcc_commodity;
        if (! empty($filters['commodity']) && HvccCatalog::isKnownCommodity((string) $filters['commodity'])) {
            $hvccCommodity = (string) $filters['commodity'];
            $crop = 'HVCC';
        }
        [$plotArea, $plantArea] = $this->cropAreaSubqueries($crop, $hvccCommodity);
        $damage = $this->damageSeveritySubquery(
            ! empty($filters['rdana_validated']) ? ($filters['rdana_months'] ?? null) : null,
            ! empty($filters['rdana_validated']) ? ($filters['rdana_min_severity'] ?? null) : null,
        );
        $outbreak = $this->pestOutbreakSubquery();

        $query = DB::table('farmers')
            ->leftJoinSub($plotArea, 'plots', fn ($join) => $join->on('plots.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($plantArea, 'planted', fn ($join) => $join->on('planted.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($damage, 'damage', fn ($join) => $join->on('damage.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($outbreak, 'outbreak', fn ($join) => $join->on('outbreak.farmer_id', '=', 'farmers.id'))
            ->whereNull('farmers.deleted_at')
            ->when(empty($filters['include_unregistered']), function ($q) {
                $q->whereNotNull('farmers.rsbsa_no')->where('farmers.rsbsa_no', '!=', '');
            })
            ->whereRaw($this->farmAreaSql().' > 0')
            ->select([
                'farmers.id',
                'farmers.rsbsa_no',
                'farmers.is_temporary',
                'farmers.surname',
                'farmers.first_name',
                'farmers.middle_name',
                'farmers.permanent_brgy',
                'farmers.mobile_number',
                'farmers.is_pwd',
                'farmers.birthdate',
                'damage.damage_percentage',
            ])
            ->selectRaw($this->farmAreaSql().' as farm_area')
            ->selectRaw('CASE WHEN outbreak.farmer_id IS NULL THEN 0 ELSE 1 END as is_outbreak');

        $this->applyBarangayScope($query, $program);

        if (! empty($filters['barangays'])) {
            $query->whereIn('farmers.permanent_brgy', $filters['barangays']);
        }

        if (! empty($filters['rsbsa_nos'])) {
            $query->whereIn('farmers.rsbsa_no', $filters['rsbsa_nos']);
        }

        if (! empty($filters['farmer_ids'])) {
            $query->whereIn('farmers.id', $filters['farmer_ids']);
        }

        if (isset($filters['min_ha']) && $filters['min_ha'] !== null) {
            $query->whereRaw($this->farmAreaSql().' + 0.0000001 >= ?', [$filters['min_ha']]);
        }

        if (isset($filters['max_ha']) && $filters['max_ha'] !== null) {
            $query->whereRaw($this->farmAreaSql().' <= ? + 0.0000001', [$filters['max_ha']]);
        }

        if (! empty($filters['rdana_validated'])) {
            $query->whereNotNull('damage.farmer_id');
        }

        if (! empty($filters['pest_outbreak'])) {
            $query->whereNotNull('outbreak.farmer_id');
        }

        if (! empty($filters['social_priority'])) {
            $seniorCutoff = now()->subYears(60)->toDateString();
            $query->where(function ($q) use ($seniorCutoff) {
                $q->where('farmers.is_pwd', true)
                    ->orWhere('farmers.is_pwd', 1)
                    ->orWhereDate('farmers.birthdate', '<=', $seniorCutoff);
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('farmers.rsbsa_no', 'like', $like)
                    ->orWhere('farmers.surname', 'like', $like)
                    ->orWhere('farmers.first_name', 'like', $like)
                    ->orWhere('farmers.middle_name', 'like', $like);
            });
        }

        if (! empty($filters['exclude_history'])) {
            $this->excludeOverlappingSubsidyHistory($query, $program);
        }

        return $query;
    }

    /**
     * Drop farmers already Pending, Waitlisted, or Claimed on another Draft/Active
     * program for the same crop (anti-duplicate across the current season).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function excludeOverlappingSubsidyHistory(\Illuminate\Database\Query\Builder $query, SubsidyProgram $program): void
    {
        $query->whereNotExists(function ($sub) use ($program) {
            $sub->select(DB::raw(1))
                ->from('tbl_subsidy_beneficiaries as other_b')
                ->join('tbl_subsidy_programs as other_p', 'other_p.id', '=', 'other_b.program_id')
                ->where(function ($match) {
                    $match->whereColumn('other_b.farmer_id', 'farmers.id')
                        ->orWhereColumn('other_b.farmer_rsbsa_no', 'farmers.rsbsa_no');
                })
                ->where('other_b.program_id', '!=', $program->id)
                ->where('other_p.target_crop', $program->target_crop)
                ->whereIn('other_p.status', ['Draft', 'Active'])
                ->whereNull('other_b.deleted_at')
                ->whereIn('other_b.status', ['Pending', 'Waitlisted', 'Claimed']);
        });
    }

    /**
     * Walk an already-ordered farmer list, Pending until either unit's buffer
     * is exhausted, then Waitlisted for the rest. Claimed rows are not rewritten.
     * Pending allocations already reserved by farmers outside this list are
     * subtracted from the warehouse buffer first.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $farmers
     * @return array{generated_count: int, updated_count: int, pending_count: int, waitlisted_count: int, eligible_count: int}
     */
    private function persistAllocatedRows(SubsidyProgram $program, \Illuminate\Support\Collection $farmers, string $selectionMode): array
    {
        $prepared = $farmers->map(function ($farmer) use ($program) {
            $allocation = $this->allocationForArea($program, (float) $farmer->farm_area);
            if ($allocation === null) {
                return null;
            }

            return [
                'farmer' => $farmer,
                'primary' => $allocation['primary'],
                'secondary' => $allocation['secondary'],
                'tier' => $this->priorityTierForFarmer($farmer, (float) $farmer->farm_area),
            ];
        })->filter()->values();

        $rsbsaNos = $prepared->map(fn ($row) => $row['farmer']->rsbsa_no)->filter()->values()->all();
        $farmerIds = $prepared->map(fn ($row) => $row['farmer']->id)->filter()->values()->all();
        $reserved = $this->reservedPendingOutside($program, $rsbsaNos, $farmerIds);

        $primaryLeft = max(0, (float) $program->remaining_quantity - $reserved['primary']);
        $secondaryLeft = $program->secondary_unit !== null
            ? max(0, (float) ($program->secondary_remaining_quantity ?? 0) - $reserved['secondary'])
            : null;

        $cutoff = false;
        $now = now();
        $rows = [];

        foreach ($prepared as $row) {
            $primary = $row['primary'];
            $secondary = $row['secondary'];
            $fitsPrimary = ($primaryLeft + 0.0000001) >= $primary;
            $fitsSecondary = $secondaryLeft === null
                || $secondary === null
                || ($secondaryLeft + 0.0000001) >= $secondary;

            if ($cutoff || ! $fitsPrimary || ! $fitsSecondary) {
                $cutoff = true;
                $status = 'Waitlisted';
            } else {
                $status = 'Pending';
                $primaryLeft -= $primary;
                if ($secondaryLeft !== null && $secondary !== null) {
                    $secondaryLeft -= $secondary;
                }
            }

            $rows[] = [
                'farmer_id' => $row['farmer']->id,
                'farmer_rsbsa_no' => $row['farmer']->rsbsa_no ?: null,
                'is_walkin' => (bool) ($row['farmer']->is_temporary ?? false) || empty($row['farmer']->rsbsa_no),
                'calculated_allocation' => $primary,
                'calculated_allocation_secondary' => $secondary,
                'status' => $status,
                'priority_tier' => $row['tier'],
                'selection_mode' => $selectionMode,
                'updated_at' => $now,
            ];
        }

        $generatedCount = 0;
        $updatedCount = 0;
        $pendingCount = 0;
        $waitlistedCount = 0;

        DB::transaction(function () use ($rows, $program, $now, &$generatedCount, &$updatedCount, &$pendingCount, &$waitlistedCount) {
            foreach ($rows as $row) {
                $existingQuery = DB::table('tbl_subsidy_beneficiaries')
                    ->where('program_id', $program->id)
                    ->where(function ($q) use ($row) {
                        $q->where('farmer_id', $row['farmer_id']);
                        if (! empty($row['farmer_rsbsa_no'])) {
                            $q->orWhere('farmer_rsbsa_no', $row['farmer_rsbsa_no']);
                        }
                    });
                SubsidyBeneficiary::applyNotDeleted($existingQuery);
                $existing = $existingQuery->first();

                if ($existing && $existing->status === 'Claimed') {
                    continue;
                }

                if ($row['status'] === 'Waitlisted') {
                    $waitlistedCount++;
                } else {
                    $pendingCount++;
                }

                if (! $existing) {
                    DB::table('tbl_subsidy_beneficiaries')->insert([
                        'id' => (string) Str::uuid(),
                        'program_id' => $program->id,
                        'farmer_id' => $row['farmer_id'],
                        'farmer_rsbsa_no' => $row['farmer_rsbsa_no'],
                        'is_walkin' => $row['is_walkin'],
                        'calculated_allocation' => $row['calculated_allocation'],
                        'calculated_allocation_secondary' => $row['calculated_allocation_secondary'],
                        'status' => $row['status'],
                        'priority_tier' => $row['priority_tier'],
                        'selection_mode' => $row['selection_mode'],
                        'claimed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $generatedCount++;
                    continue;
                }

                DB::table('tbl_subsidy_beneficiaries')
                    ->where('id', $existing->id)
                    ->update([
                        'calculated_allocation' => $row['calculated_allocation'],
                        'calculated_allocation_secondary' => $row['calculated_allocation_secondary'],
                        'status' => $row['status'],
                        'priority_tier' => $row['priority_tier'],
                        'selection_mode' => $row['selection_mode'],
                        'updated_at' => $now,
                    ]);
                $updatedCount++;
            }
        });

        return [
            'generated_count' => $generatedCount,
            'updated_count' => $updatedCount,
            'pending_count' => $pendingCount,
            'waitlisted_count' => $waitlistedCount,
            'eligible_count' => count($rows),
        ];
    }

    /**
     * Pending allocations already on this program for farmers not in the
     * current pass. Those rows still reserve warehouse buffer.
     *
     * @param  array<int, string>  $rsbsaNos
     * @return array{primary: float, secondary: float}
     */
    private function reservedPendingOutside(SubsidyProgram $program, array $rsbsaNos, array $farmerIds = []): array
    {
        $query = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id)
            ->where('status', 'Pending');
        SubsidyBeneficiary::applyNotDeleted($query);

        if (count($farmerIds) > 0) {
            $query->where(function ($q) use ($farmerIds, $rsbsaNos) {
                $q->whereNotIn('farmer_id', $farmerIds);
                if (count($rsbsaNos) > 0) {
                    $q->where(function ($inner) use ($rsbsaNos) {
                        $inner->whereNull('farmer_rsbsa_no')
                            ->orWhereNotIn('farmer_rsbsa_no', $rsbsaNos);
                    });
                }
            });
        } elseif (count($rsbsaNos) > 0) {
            $query->whereNotIn('farmer_rsbsa_no', $rsbsaNos);
        }

        $secondaryQuery = clone $query;

        return [
            'primary' => (float) ($query->sum('calculated_allocation') ?? 0),
            'secondary' => (float) ($secondaryQuery->sum('calculated_allocation_secondary') ?? 0),
        ];
    }

    /**
     * Whole-item allocation from plot-first area, program min/max ha, and rates.
     * Null when the farmer is below the floor or both units round to zero.
     *
     * @return array{primary: int, secondary: ?int}|null
     */
    private function allocationForArea(SubsidyProgram $program, float $farmArea): ?array
    {
        $minHa = (float) ($program->min_hectares_limit ?? 0);
        if ($minHa > 0 && $farmArea + 0.0000001 < $minHa) {
            return null;
        }

        $eligibleArea = min($farmArea, (float) $program->max_hectares_limit);
        $allocation = (int) floor(($eligibleArea * (float) $program->items_per_hectare) + 0.0000001);
        $allocation = $this->cashCappedAllocation($program, $allocation);

        $allocationSecondary = null;
        if ($program->secondary_unit !== null) {
            $allocationSecondary = (int) floor(
                ($eligibleArea * (float) $program->secondary_items_per_hectare) + 0.0000001
            );
        }

        if ($allocation < 1 && ($allocationSecondary === null || $allocationSecondary < 1)) {
            return null;
        }

        return [
            'primary' => $allocation,
            'secondary' => $allocationSecondary,
        ];
    }

    /**
     * Tier 1 vulnerable (PWD or age 60+), tier 2 approved calamity or outbreak,
     * tier 3 smallholder (1.5 ha or less). Null when none match.
     */
    private function priorityTierForFarmer(object $farmer, float $farmArea): ?int
    {
        if ((bool) ($farmer->is_pwd ?? false) || $this->isSeniorCitizen($farmer->birthdate ?? null)) {
            return 1;
        }

        $hasDamage = isset($farmer->damage_percentage) && $farmer->damage_percentage !== null && (float) $farmer->damage_percentage > 0;
        $hasOutbreak = (bool) ($farmer->is_outbreak ?? false);
        if ($hasDamage || $hasOutbreak) {
            return 2;
        }

        if ($farmArea > 0 && $farmArea <= 1.5 + 0.0000001) {
            return 3;
        }

        return null;
    }

    private function tierSortKey(object $farmer): int
    {
        return $this->priorityTierForFarmer($farmer, (float) $farmer->farm_area) ?? 99;
    }

    private function isSeniorCitizen(mixed $birthdate): bool
    {
        if ($birthdate === null || $birthdate === '') {
            return false;
        }

        try {
            return Carbon::parse($birthdate)->age >= 60;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Highest approved RDANA damage percentage per farmer.
     */
    private function damageSeveritySubquery(?int $months = null, ?float $minSeverity = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('damage_assessments')
            ->where('status', 'Approved')
            ->whereNull('deleted_at')
            ->whereNotNull('farmer_id');

        if ($months !== null && $months > 0) {
            $query->whereDate('date_of_calamity', '>=', now()->subMonths($months)->toDateString());
        }

        if ($minSeverity !== null && $minSeverity > 0) {
            $query->where('damage_percentage', '>=', $minSeverity);
        }

        return $query
            ->groupBy('farmer_id')
            ->select('farmer_id')
            ->selectRaw('MAX(damage_percentage) as damage_percentage');
    }

    /**
     * Farmers with at least one flagged pest outbreak.
     */
    private function pestOutbreakSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('pest_monitoring')
            ->where('is_outbreak', true)
            ->whereNull('deleted_at')
            ->whereNotNull('farmer_id')
            ->groupBy('farmer_id')
            ->select('farmer_id');
    }

    /**
     * Prefer registered crop-matched plot area; fall back to planted only when
     * the farmer has no matching plots. Plot-first avoids seasonal Active
     * planting logs stacking into inflated farm_area / allocations.
     */
    private function farmAreaSql(): string
    {
        return 'CASE WHEN COALESCE(plots.area, 0) > 0 THEN plots.area ELSE COALESCE(planted.area, 0) END';
    }

    /**
     * Crop-area helpers: RSBSA farm plots + active planting logs.
     * `Both` sums rice + corn parcels / planting logs.
     * Planted fallback uses the latest Active log per plot (not lifetime SUM).
     */
    private function cropAreaForFarmer(string $farmerId, string $targetCrop, ?string $hvccCommodity = null): float
    {
        $plotQuery = DB::table('farm_plots')
            ->where('farmer_id', $farmerId)
            ->whereNull('deleted_at');
        $this->applyCropFilter($plotQuery, 'commodity', $targetCrop, $hvccCommodity);
        $plotHa = (float) ($plotQuery->sum('size_ha') ?? 0);

        if ($plotHa > 0) {
            return $plotHa;
        }

        return $this->latestPlantedAreaForFarmer($farmerId, $targetCrop, $hvccCommodity);
    }

    /**
     * Latest Active planted ha per plot for one farmer (null plot_id = one bucket).
     */
    private function latestPlantedAreaForFarmer(string $farmerId, string $targetCrop, ?string $hvccCommodity = null): float
    {
        $ranked = DB::table('planting_logs')
            ->where('farmer_id', $farmerId)
            ->where('status', 'Active')
            ->whereNull('deleted_at');
        $this->applyPlantingCropFilter($ranked, $targetCrop, $hvccCommodity);
        $ranked
            ->select(['farm_plot_id', 'area_planted'])
            ->selectRaw(
                "ROW_NUMBER() OVER (
                    PARTITION BY COALESCE(farm_plot_id, '00000000-0000-0000-0000-000000000000')
                    ORDER BY date_planted DESC, created_at DESC, id DESC
                ) as rn"
            );

        return (float) (DB::query()
            ->fromSub($ranked, 'latest_plantings')
            ->where('rn', 1)
            ->sum('area_planted') ?? 0);
    }

    /**
     * @return array{0: \Illuminate\Database\Query\Builder, 1: \Illuminate\Database\Query\Builder}
     */
    private function cropAreaSubqueries(string $targetCrop, ?string $hvccCommodity = null): array
    {
        $plotArea = DB::table('farm_plots')->whereNull('deleted_at');
        $this->applyCropFilter($plotArea, 'commodity', $targetCrop, $hvccCommodity);
        $plotArea->groupBy('farmer_id')->select('farmer_id')->selectRaw('SUM(size_ha) as area');

        $rankedPlantings = DB::table('planting_logs')
            ->where('status', 'Active')
            ->whereNull('deleted_at');
        $this->applyPlantingCropFilter($rankedPlantings, $targetCrop, $hvccCommodity);
        $rankedPlantings
            ->select(['farmer_id', 'farm_plot_id', 'area_planted'])
            ->selectRaw(
                "ROW_NUMBER() OVER (
                    PARTITION BY farmer_id, COALESCE(farm_plot_id, '00000000-0000-0000-0000-000000000000')
                    ORDER BY date_planted DESC, created_at DESC, id DESC
                ) as rn"
            );

        $plantArea = DB::query()
            ->fromSub($rankedPlantings, 'latest_plantings')
            ->where('rn', 1)
            ->groupBy('farmer_id')
            ->select('farmer_id')
            ->selectRaw('SUM(area_planted) as area');

        return [$plotArea, $plantArea];
    }

    private function isCashProgram(SubsidyProgram $program): bool
    {
        if ($program->item_type) {
            return SubsidyCatalog::isCash($program->item_type);
        }

        return strcasecmp(trim((string) $program->unit_of_measurement), 'Cash (PHP)') === 0;
    }

    private function cashCappedAllocation(SubsidyProgram $program, int $allocation): int
    {
        return $allocation;
    }

    /**
     * When target_barangays is set, limit masterlist generation to those barangays.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyBarangayScope(\Illuminate\Database\Query\Builder $query, SubsidyProgram $program): void
    {
        $barangays = $program->target_barangays;
        if (is_array($barangays) && count($barangays) > 0) {
            $query->whereIn('farmers.permanent_brgy', $barangays);
        }
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    private function applyPlantingCropFilter(\Illuminate\Database\Query\Builder $query, string $targetCrop, ?string $hvccCommodity = null): \Illuminate\Database\Query\Builder
    {
        if (strtolower(trim($targetCrop)) !== 'hvcc') {
            return $this->applyCropFilter($query, 'crop_type', $targetCrop);
        }

        $query->whereRaw('LOWER(crop_type) = ?', ['hvcc']);
        if ($hvccCommodity) {
            $query->whereRaw('LOWER(hvcc_commodity) = ?', [strtolower($hvccCommodity)]);
        }

        return $query;
    }

    private function applyCropFilter(\Illuminate\Database\Query\Builder $query, string $column, string $targetCrop, ?string $hvccCommodity = null)
    {
        $crop = strtolower(trim($targetCrop));

        if ($crop === 'both') {
            return $query->where(function ($q) use ($column) {
                $q->whereRaw("LOWER({$column}) like ?", ['%rice%'])
                    ->orWhereRaw("LOWER({$column}) like ?", ['%corn%']);
            })->whereRaw("LOWER({$column}) not like ?", ['%hvcc%']);
        }

        if ($crop === 'hvcc') {
            return $query->where(function ($q) use ($column, $hvccCommodity) {
                $q->whereRaw("LOWER({$column}) like ?", ['%hvcc%'])
                    ->orWhereRaw("LOWER({$column}) like ?", ['%high-value%'])
                    ->orWhereRaw("LOWER({$column}) = ?", ['hvc']);
                $names = $hvccCommodity ? [$hvccCommodity] : HvccCatalog::allCommodities();
                foreach ($names as $name) {
                    $q->orWhereRaw("LOWER({$column}) = ?", [strtolower($name)]);
                }
            });
        }

        return $query->whereRaw("LOWER({$column}) like ?", ['%'.$crop.'%'])
            ->whereRaw("LOWER({$column}) not like ?", ['%hvcc%']);
    }

    /** Dexie queues ISO-8601 (`...Z`); MySQL timestamp needs a Carbon instance. */
    private function parseClaimedAt(mixed $value): \Carbon\CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }
    }
}
