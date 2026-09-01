<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\SubsidyProgram;
use App\Support\OfficialBarangays;
use App\Support\SubsidyCatalog;
use App\Traits\DecodesBase64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubsidyController extends Controller
{
    use DecodesBase64Image;
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
            'target_crop' => ['required', Rule::in(['Rice', 'Corn', 'Both'])],
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

        $updates = ['reorder_level' => $validated['reorder_level'] ?? null];

        if (! $program->item_type) {
            $updates['unit_of_measurement'] = $validated['unit_of_measurement'] ?? $program->unit_of_measurement;
        }

        if ($program->secondary_unit) {
            $updates['secondary_reorder_level'] = $validated['secondary_reorder_level'] ?? null;
        }

        $program->update($updates);

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
        $program->update(['status' => $validated['status']]);

        return response()->json([
            'status' => 'success',
            'message' => "Program marked {$validated['status']}.",
            'data' => $program->fresh(),
        ]);
    }

    /**
     * Generate eligible beneficiaries from current, active planting records.
     * Dual-unit catalog items (e.g. Hybrid Seed: kg + bags) compute an
     * allocation for each unit from its own per-hectare rate.
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

        [$plotArea, $plantArea] = $this->cropAreaSubqueries((string) $program->target_crop);

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

        $eligibleFarmersQuery = DB::table('farmers')
            ->leftJoinSub($plotArea, 'plots', fn ($join) => $join->on('plots.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($plantArea, 'planted', fn ($join) => $join->on('planted.farmer_id', '=', 'farmers.id'))
            ->whereNull('farmers.deleted_at')
            ->whereNotNull('farmers.rsbsa_no')
            ->where('farmers.rsbsa_no', '!=', '')
            ->whereRaw('COALESCE(planted.area, plots.area, 0) > 0')
            ->select([
                'farmers.id',
                'farmers.rsbsa_no',
            ])
            ->selectRaw('COALESCE(planted.area, plots.area, 0) as farm_area');
        $this->applyBarangayScope($eligibleFarmersQuery, $program);
        $eligibleFarmers = $eligibleFarmersQuery->get();

        $now = now();
        $minHa = (float) ($program->min_hectares_limit ?? 0);
        $isDualUnit = $program->secondary_unit !== null;
        $rows = $eligibleFarmers
            ->map(function ($farmer) use ($program, $now, $minHa, $isDualUnit) {
                $farmArea = (float) $farmer->farm_area;
                if ($minHa > 0 && $farmArea + 0.0000001 < $minHa) {
                    return null;
                }

                $eligibleArea = min(
                    $farmArea,
                    (float) $program->max_hectares_limit
                );

                // Allocations are whole items; partial items are not distributable.
                $allocation = (int) floor(
                    ($eligibleArea * (float) $program->items_per_hectare) + 0.0000001
                );
                $allocation = $this->cashCappedAllocation($program, $allocation);

                $allocationSecondary = null;
                if ($isDualUnit) {
                    $allocationSecondary = (int) floor(
                        ($eligibleArea * (float) $program->secondary_items_per_hectare) + 0.0000001
                    );
                }

                if ($allocation < 1 && ($allocationSecondary === null || $allocationSecondary < 1)) {
                    return null;
                }

                return [
                    'id' => (string) Str::uuid(),
                    'program_id' => $program->id,
                    'farmer_rsbsa_no' => $farmer->rsbsa_no,
                    'calculated_allocation' => $allocation,
                    'calculated_allocation_secondary' => $allocationSecondary,
                    'status' => 'Pending',
                    'claimed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $generatedCount = DB::transaction(function () use ($rows) {
            $inserted = 0;

            foreach (array_chunk($rows, 500) as $chunk) {
                $inserted += DB::table('tbl_subsidy_beneficiaries')->insertOrIgnore($chunk);
            }

            return $inserted;
        });

        $masterlistCount = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id)
            ->count();

        $message = "{$generatedCount} new beneficiaries added to the masterlist.";
        if ($generatedCount === 0 && count($rows) === 0) {
            $message = $skippedNoRsbsa > 0
                ? "No eligible farmers found. {$skippedNoRsbsa} matching farmer(s) were skipped because they have no RSBSA number."
                : 'No eligible farmers found. Matching farmers need an RSBSA number plus a Rice/Corn farm plot or an active planting log.';
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'program_id' => $program->id,
                'eligible_count' => count($rows),
                'generated_count' => $generatedCount,
                'skipped_no_rsbsa' => $skippedNoRsbsa,
                'masterlist_count' => $masterlistCount,
            ],
        ]);
    }

    /**
     * Return a compact, spreadsheet-ready masterlist for one program.
     */
    public function masterlist(string $id): JsonResponse
    {
        $program = SubsidyProgram::query()->findOrFail($id);

        [$plotArea, $plantArea] = $this->cropAreaSubqueries((string) $program->target_crop);

        $masterlist = DB::table('tbl_subsidy_beneficiaries as beneficiaries')
            ->join('farmers', 'farmers.rsbsa_no', '=', 'beneficiaries.farmer_rsbsa_no')
            ->leftJoinSub($plotArea, 'plots', fn ($join) => $join->on('plots.farmer_id', '=', 'farmers.id'))
            ->leftJoinSub($plantArea, 'planted', fn ($join) => $join->on('planted.farmer_id', '=', 'farmers.id'))
            ->where('beneficiaries.program_id', $program->id)
            ->whereNull('farmers.deleted_at')
            ->orderBy('farmers.surname')
            ->orderBy('farmers.first_name')
            ->select([
                'beneficiaries.id as beneficiary_id',
                'beneficiaries.farmer_rsbsa_no as rsbsa_no',
                'farmers.surname as last_name',
                'farmers.first_name',
                'farmers.middle_name',
                'farmers.permanent_brgy as barangay',
                'beneficiaries.calculated_allocation',
                'beneficiaries.calculated_allocation_secondary',
                'beneficiaries.status',
            ])
            ->selectRaw('ROUND(COALESCE(planted.area, plots.area, 0), 4) as farm_area')
            ->get();

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
        ]);

        $result = DB::transaction(function () use ($id, $beneficiaryId, $validated) {
            $program = SubsidyProgram::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($program->status !== 'Active') {
                return ['error' => 'This subsidy program is not active. Claims are frozen.', 'code' => 400];
            }

            $beneficiary = DB::table('tbl_subsidy_beneficiaries')
                ->where('id', $beneficiaryId)
                ->where('program_id', $id)
                ->first();

            if (!$beneficiary) {
                return ['error' => 'Beneficiary not found on this program.', 'code' => 404];
            }

            if ($beneficiary->status === 'Claimed') {
                return ['error' => 'This beneficiary has already claimed their allocation.', 'code' => 409];
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

            DB::table('tbl_subsidy_beneficiaries')
                ->where('id', $beneficiaryId)
                ->update([
                    'status' => 'Claimed',
                    'claimed_at' => now(),
                    'claimed_by' => auth()->id(),
                    'photo_proof_path' => $photoPath,
                    'updated_at' => now(),
                ]);

            return ['program' => $program->fresh()];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
            ], $result['code']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Beneficiary marked as Claimed. Stock updated.',
            'data' => [
                'program' => $result['program'],
            ],
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

        if (! $farmer->rsbsa_no) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This farmer has no RSBSA number and cannot claim subsidy.',
            ], 400);
        }

        $beneficiary = DB::table('tbl_subsidy_beneficiaries')
            ->where('program_id', $program->id)
            ->where('farmer_rsbsa_no', $farmer->rsbsa_no)
            ->first();

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
        $totalFarmSize = $this->cropAreaForFarmer($farmer->id, (string) $program->target_crop);
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
        ]);

        $result = $this->executeClaim($id, $validated, $request->user()?->id);

        if ($result['outcome'] !== 'synced') {
            return response()->json([
                'status' => 'error',
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

            if (! empty($item['beneficiary_id'])) {
                $beneficiaryQuery->where('id', $item['beneficiary_id']);
            } elseif ($farmer?->rsbsa_no) {
                $beneficiaryQuery->where('farmer_rsbsa_no', $farmer->rsbsa_no);
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

            DB::table('tbl_subsidy_beneficiaries')
                ->where('id', $beneficiary->id)
                ->update([
                    'status' => 'Claimed',
                    'claimed_at' => $this->parseClaimedAt($item['claimed_at'] ?? null),
                    'claimed_by' => $technicianId ?? auth()->id(),
                    'photo_proof_path' => $photoPath,
                    'updated_at' => now(),
                ]);

            return [
                'program' => $program->fresh(),
                'beneficiary' => $beneficiary,
                'farmer' => $farmer,
            ];
        });

        if (isset($result['error'])) {
            return ['outcome' => $result['outcome'], 'code' => $result['code'], 'message' => $result['error']];
        }

        $farmerName = $result['farmer']
            ? trim($result['farmer']->surname.', '.$result['farmer']->first_name)
            : ($result['beneficiary']->farmer_rsbsa_no ?? 'Farmer');

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
     * Crop-area subqueries: RSBSA farm plots + active planting logs.
     * `Both` sums rice + corn parcels / planting logs.
     */
    private function cropAreaForFarmer(string $farmerId, string $targetCrop): float
    {
        $plotQuery = DB::table('farm_plots')
            ->where('farmer_id', $farmerId)
            ->whereNull('deleted_at');
        $this->applyCropFilter($plotQuery, 'commodity', $targetCrop);
        $plotHa = (float) ($plotQuery->sum('size_ha') ?? 0);

        $plantQuery = DB::table('planting_logs')
            ->where('farmer_id', $farmerId)
            ->where('status', 'Active');
        $this->applyCropFilter($plantQuery, 'crop_type', $targetCrop);
        $plantHa = (float) ($plantQuery->sum('area_planted') ?? 0);

        return $plantHa > 0 ? $plantHa : $plotHa;
    }

    /**
     * @return array{0: \Illuminate\Database\Query\Builder, 1: \Illuminate\Database\Query\Builder}
     */
    private function cropAreaSubqueries(string $targetCrop): array
    {
        $plotArea = DB::table('farm_plots')->whereNull('deleted_at');
        $this->applyCropFilter($plotArea, 'commodity', $targetCrop);
        $plotArea->groupBy('farmer_id')->select('farmer_id')->selectRaw('SUM(size_ha) as area');

        $plantArea = DB::table('planting_logs')->where('status', 'Active');
        $this->applyCropFilter($plantArea, 'crop_type', $targetCrop);
        $plantArea->groupBy('farmer_id')->select('farmer_id')->selectRaw('SUM(area_planted) as area');

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
     */
    private function applyBarangayScope($query, SubsidyProgram $program): void
    {
        $barangays = $program->target_barangays;
        if (is_array($barangays) && count($barangays) > 0) {
            $query->whereIn('farmers.permanent_brgy', $barangays);
        }
    }

    private function applyCropFilter($query, string $column, string $targetCrop)
    {
        $crop = strtolower(trim($targetCrop));

        if ($crop === 'both') {
            return $query->where(function ($q) use ($column) {
                $q->whereRaw("LOWER({$column}) like ?", ['%rice%'])
                    ->orWhereRaw("LOWER({$column}) like ?", ['%corn%']);
            });
        }

        return $query->whereRaw("LOWER({$column}) like ?", ['%'.$crop.'%']);
    }

    /** Dexie queues ISO-8601 (`...Z`); MySQL timestamp needs a Carbon instance. */
    private function parseClaimedAt(mixed $value): Carbon
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
