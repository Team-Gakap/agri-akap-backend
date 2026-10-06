<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\SeedRelease;
use App\Models\SeedVariety;
use App\Support\AuditRemarks;
use App\Traits\LogsReportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SeedVarietyController extends Controller
{
    use LogsReportAudit;

    public function index(): JsonResponse
    {
        $varieties = SeedVariety::query()
            ->orderByRaw('COALESCE(sort_order, 9999)')
            ->orderBy('variety_name')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Seed varieties loaded.',
            'data' => $varieties,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variety_name' => 'required|string|max:120|unique:tbl_seed_varieties,variety_name',
            'unit' => 'nullable|string|max:64',
            'bags_per_hectare' => 'required|numeric|min:0.01|max:100000',
            'total_quantity' => 'required|numeric|min:0|max:1000000',
            'reorder_level' => 'nullable|numeric|min:0|max:1000000',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before adding a seed variety.');

        $qty = (float) $validated['total_quantity'];
        $variety = SeedVariety::create([
            'variety_name' => trim($validated['variety_name']),
            'unit' => $validated['unit'] ?? 'Bags',
            'bags_per_hectare' => $validated['bags_per_hectare'],
            'total_quantity' => $qty,
            'remaining_quantity' => $qty,
            'reorder_level' => $validated['reorder_level'] ?? null,
            'sort_order' => $validated['sort_order'] ?? null,
            'is_active' => true,
        ]);

        $this->logReportAudit('seed_variety.created', $variety->id, [
            'variety_name' => $variety->variety_name,
            'total_quantity' => $qty,
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Seed variety created.',
            'data' => $variety,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $variety = SeedVariety::query()->findOrFail($id);
        $validated = $request->validate([
            'variety_name' => [
                'sometimes', 'string', 'max:120',
                Rule::unique('tbl_seed_varieties', 'variety_name')->ignore($variety->id),
            ],
            'unit' => 'nullable|string|max:64',
            'bags_per_hectare' => 'sometimes|numeric|min:0.01|max:100000',
            'reorder_level' => 'nullable|numeric|min:0|max:1000000',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'sometimes|boolean',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before updating a seed variety.');

        $variety->fill($validated);
        $variety->save();

        $this->logReportAudit('seed_variety.updated', $variety->id, [
            'after' => $variety->only(['variety_name', 'bags_per_hectare', 'is_active', 'reorder_level']),
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Seed variety updated.',
            'data' => $variety->fresh(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'varieties' => 'required|array|min:1',
            'varieties.*.variety_name' => 'required|string|max:120',
            'varieties.*.quantity' => 'required|numeric|min:0|max:1000000',
            'varieties.*.bags_per_hectare' => 'nullable|numeric|min:0.01|max:100000',
            'varieties.*.unit' => 'nullable|string|max:64',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before syncing seed varieties.');

        $hasClaims = SeedRelease::query()->where('status', 'Claimed')->exists();

        DB::transaction(function () use ($validated, $hasClaims) {
            $order = 0;
            foreach ($validated['varieties'] as $row) {
                $name = trim($row['variety_name']);
                $qty = (float) $row['quantity'];
                $existing = SeedVariety::query()->where('variety_name', $name)->first();
                if ($existing) {
                    if (! $hasClaims) {
                        $existing->total_quantity = $qty;
                        $existing->remaining_quantity = $qty;
                    }
                    if (isset($row['bags_per_hectare'])) {
                        $existing->bags_per_hectare = $row['bags_per_hectare'];
                    }
                    if (! empty($row['unit'])) {
                        $existing->unit = $row['unit'];
                    }
                    $existing->sort_order = $order++;
                    $existing->is_active = true;
                    $existing->save();
                } else {
                    SeedVariety::create([
                        'variety_name' => $name,
                        'unit' => $row['unit'] ?? 'Bags',
                        'bags_per_hectare' => $row['bags_per_hectare'] ?? 1,
                        'total_quantity' => $qty,
                        'remaining_quantity' => $qty,
                        'sort_order' => $order++,
                        'is_active' => true,
                    ]);
                }
            }
        });

        $this->logReportAudit('seed_variety.synced', null, [
            'count' => count($validated['varieties']),
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Seed varieties saved.',
            'data' => SeedVariety::query()->orderByRaw('COALESCE(sort_order, 9999)')->orderBy('variety_name')->get(),
        ]);
    }

    public function restock(Request $request, string $id): JsonResponse
    {
        $variety = SeedVariety::query()->findOrFail($id);
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01|max:1000000',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before restocking seed variety inventory.');

        $qty = (float) $validated['quantity'];
        $variety->total_quantity = (float) $variety->total_quantity + $qty;
        $variety->remaining_quantity = (float) $variety->remaining_quantity + $qty;
        $variety->save();

        $this->logReportAudit('seed_variety.restocked', $variety->id, [
            'added' => $qty,
            'remaining' => (float) $variety->remaining_quantity,
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Variety restocked.',
            'data' => $variety,
        ]);
    }

    /**
     * Registry-based eligibility check — no subsidy program required.
     */
    public function verifyFarmer(Request $request): JsonResponse
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

        $farmer = $this->resolveFarmer($validated['farmer_id'] ?? null, $validated['rsbsa_no'] ?? null);
        if (! $farmer) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'No registered farmer matches that ID / RSBSA.',
            ], 404);
        }

        if (! empty($farmer->subsidy_exclusion_reason)) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This farmer is excluded from subsidies: '.$farmer->subsidy_exclusion_reason,
                'data' => ['exclusion_reason' => $farmer->subsidy_exclusion_reason],
            ], 409);
        }

        $claimedVarietyIds = SeedRelease::query()
            ->where('farmer_id', $farmer->id)
            ->where('status', 'Claimed')
            ->pluck('variety_id')
            ->all();

        $varieties = SeedVariety::query()
            ->where('is_active', true)
            ->where('remaining_quantity', '>', 0)
            ->orderByRaw('COALESCE(sort_order, 9999)')
            ->orderBy('variety_name')
            ->get()
            ->map(fn (SeedVariety $v) => [
                'id' => $v->id,
                'variety_name' => $v->variety_name,
                'unit' => $v->unit,
                'bags_per_hectare' => (float) $v->bags_per_hectare,
                'remaining_quantity' => (float) $v->remaining_quantity,
                'already_claimed' => in_array($v->id, $claimedVarietyIds, true),
            ])
            ->values();

        $area = (float) ($farmer->total_farm_area_ha ?? 0);

        return response()->json([
            'status' => 'success',
            'eligible' => true,
            'message' => 'Farmer is eligible for seed release.',
            'data' => [
                'farmer_id' => $farmer->id,
                'farmer_name' => trim($farmer->surname.', '.$farmer->first_name.' '.($farmer->middle_name ?? '')),
                'rsbsa_no' => $farmer->rsbsa_no,
                'barangay' => $farmer->permanent_brgy,
                'mobile_number' => $farmer->mobile_number,
                'total_farm_size' => $area,
                'eligible_size' => $area,
                'varieties' => $varieties,
                'source' => 'seed_variety',
            ],
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farmer_id' => 'nullable|uuid|exists:farmers,id',
            'rsbsa_no' => 'nullable|string|max:64',
            'variety_id' => 'required|uuid|exists:tbl_seed_varieties,id',
            'geo_tag_lat' => 'nullable|numeric',
            'geo_tag_long' => 'nullable|numeric',
            'override_reason' => 'nullable|string|max:255',
            'override_reason_code' => 'nullable|string|max:64',
            'override_justification' => 'nullable|string|max:2000',
            'device_id' => 'nullable|string|max:128',
        ]);

        $result = $this->executeClaim($validated, $request->user()?->id);

        $code = match ($result['outcome'] ?? 'failed') {
            'created', 'already_claimed' => 200,
            'failed' => 422,
            default => 409,
        };

        return response()->json([
            'status' => ($result['outcome'] ?? '') === 'created' || ($result['outcome'] ?? '') === 'already_claimed' ? 'success' : 'error',
            'message' => $result['message'] ?? 'Claim failed.',
            'data' => $result['data'] ?? null,
        ], $code);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{outcome: string, message: string, data?: array<string, mixed>}
     */
    public function executeClaim(array $item, ?string $technicianId = null): array
    {
        $farmer = $this->resolveFarmer($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);
        if (! $farmer) {
            return ['outcome' => 'failed', 'message' => 'No registered farmer matches that ID / RSBSA.'];
        }

        if (! empty($farmer->subsidy_exclusion_reason)) {
            return [
                'outcome' => 'failed',
                'message' => 'This farmer is excluded from subsidies: '.$farmer->subsidy_exclusion_reason,
            ];
        }

        $varietyId = $item['variety_id'] ?? null;
        if (! $varietyId) {
            return ['outcome' => 'failed', 'message' => 'Select a seed variety to release.'];
        }

        try {
            return DB::transaction(function () use ($farmer, $varietyId, $item, $technicianId) {
                $variety = SeedVariety::query()->lockForUpdate()->find($varietyId);
                if (! $variety || ! $variety->is_active) {
                    return ['outcome' => 'failed', 'message' => 'That seed variety is not available.'];
                }

                $existing = SeedRelease::query()
                    ->where('farmer_id', $farmer->id)
                    ->where('variety_id', $variety->id)
                    ->where('status', 'Claimed')
                    ->first();

                if ($existing) {
                    return [
                        'outcome' => 'already_claimed',
                        'message' => 'This farmer already claimed '.$variety->variety_name.'.',
                        'data' => [
                            'release_id' => $existing->id,
                            'variety_name' => $variety->variety_name,
                            'quantity' => (float) $existing->quantity,
                            'claimed_at' => $existing->claimed_at,
                        ],
                    ];
                }

                $area = (float) ($farmer->total_farm_area_ha ?? 0);
                if ($area <= 0) {
                    return ['outcome' => 'failed', 'message' => 'This farmer has no farm area on record.'];
                }

                $qty = (float) floor(($area * (float) $variety->bags_per_hectare) + 0.0000001);
                if ($qty <= 0) {
                    $qty = (float) $variety->bags_per_hectare > 0 ? 1.0 : 0.0;
                }
                if ($qty <= 0) {
                    return ['outcome' => 'failed', 'message' => 'Calculated allocation is zero.'];
                }

                if ((float) $variety->remaining_quantity < $qty) {
                    return [
                        'outcome' => 'failed',
                        'message' => 'Not enough stock for '.$variety->variety_name.
                            ' (need '.$qty.', have '.(float) $variety->remaining_quantity.').',
                    ];
                }

                $variety->remaining_quantity = (float) $variety->remaining_quantity - $qty;
                $variety->save();

                $release = SeedRelease::create([
                    'farmer_id' => $farmer->id,
                    'variety_id' => $variety->id,
                    'farmer_rsbsa_no' => $farmer->rsbsa_no,
                    'farm_barangay' => $farmer->permanent_brgy,
                    'farm_area_ha' => $area,
                    'quantity' => $qty,
                    'unit' => $variety->unit,
                    'released_by' => $technicianId,
                    'claimed_at' => now(),
                    'device_id' => $item['device_id'] ?? null,
                    'geo_tag_lat' => $item['geo_tag_lat'] ?? null,
                    'geo_tag_long' => $item['geo_tag_long'] ?? null,
                    'override_reason' => $item['override_reason'] ?? null,
                    'override_reason_code' => $item['override_reason_code'] ?? null,
                    'override_justification' => $item['override_justification'] ?? null,
                    'status' => 'Claimed',
                ]);

                return [
                    'outcome' => 'created',
                    'message' => 'Released '.$qty.' '.$variety->unit.' of '.$variety->variety_name.'.',
                    'data' => [
                        'release_id' => $release->id,
                        'farmer_id' => $farmer->id,
                        'farmer_name' => trim($farmer->surname.', '.$farmer->first_name),
                        'barangay' => $farmer->permanent_brgy,
                        'variety_id' => $variety->id,
                        'variety_name' => $variety->variety_name,
                        'quantity' => $qty,
                        'unit' => $variety->unit,
                        'variety_remaining' => (float) $variety->remaining_quantity,
                        'claimed_at' => $release->claimed_at,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            return ['outcome' => 'failed', 'message' => $e->getMessage() ?: 'Could not save the seed release.'];
        }
    }

    private function resolveFarmer(?string $farmerId, ?string $rsbsaNo): ?Farmer
    {
        if ($farmerId) {
            return Farmer::query()->find($farmerId);
        }
        if ($rsbsaNo) {
            return Farmer::query()->where('rsbsa_no', $rsbsaNo)->first();
        }

        return null;
    }
}
