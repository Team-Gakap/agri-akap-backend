<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Models\SubsidyProgramVariety;
use App\Support\AuditRemarks;
use App\Support\FarmerPriority;
use App\Support\SubsidyAllocation;
use App\Support\SubsidyExclusionRules;
use App\Traits\LogsReportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Technician-facing variety list / verify / claim.
 * Stock lives on tbl_subsidy_program_varieties under Active programs.
 * One claim per farmer per program cycle (any variety).
 */
class SeedVarietyController extends Controller
{
    use LogsReportAudit;

    /**
     * Flattened list of varieties on Active programs (for stock display / chips).
     */
    public function index(): JsonResponse
    {
        $varieties = SubsidyProgramVariety::query()
            ->with('program:id,program_name,seed_class,item_type,status,unit_of_measurement,items_per_hectare')
            ->whereHas('program', fn ($q) => $q->where('status', 'Active'))
            ->orderBy('sort_order')
            ->orderBy('variety_name')
            ->get()
            ->map(function (SubsidyProgramVariety $v) {
                $program = $v->program;

                return [
                    'id' => $v->id,
                    'variety_name' => $v->variety_name,
                    'unit' => $v->unit ?? $program?->unit_of_measurement ?? 'Bags',
                    'bags_per_hectare' => (float) ($v->bags_per_hectare ?? $program?->items_per_hectare ?? 1),
                    'total_quantity' => (float) $v->total_quantity,
                    'remaining_quantity' => (float) $v->remaining_quantity,
                    'target_fca' => $v->target_fca,
                    'target_barangays' => $v->target_barangays ?? [],
                    'program_id' => $v->program_id,
                    'program_name' => $program?->program_name,
                    'seed_class' => $program?->seed_class,
                    'subsidy_line' => $this->subsidyLineLabel($program?->seed_class),
                    'delivery_start_date' => optional($program?->delivery_start_date)->toDateString(),
                    'delivery_end_date' => optional($program?->delivery_end_date)->toDateString(),
                    'is_active' => $program?->status === 'Active',
                ];
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Active seed varieties loaded.',
            'data' => $varieties,
        ]);
    }

    /**
     * Compatibility: sync varieties onto a program when program_id is provided.
     * Prefer PUT /subsidies/{id}/varieties for admin batch setup.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => 'required|uuid|exists:tbl_subsidy_programs,id',
            'varieties' => 'required|array|min:1',
            'varieties.*.variety_name' => 'required|string|max:120',
            'varieties.*.quantity' => 'required|numeric|min:0|max:1000000',
            'varieties.*.bags_per_hectare' => 'nullable|numeric|min:0.01|max:100000',
            'varieties.*.unit' => 'nullable|string|max:64',
            'varieties.*.target_fca' => 'nullable|string|max:150',
            'varieties.*.target_barangays' => 'nullable|array',
            'varieties.*.target_barangays.*' => 'string|max:100',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before syncing seed varieties.');

        $program = SubsidyProgram::query()->findOrFail($validated['program_id']);
        $hasClaims = SubsidyBeneficiary::query()
            ->where('program_id', $program->id)
            ->where('status', 'Claimed')
            ->exists();

        DB::transaction(function () use ($validated, $program, $hasClaims) {
            if (! $hasClaims) {
                SubsidyProgramVariety::where('program_id', $program->id)->delete();
            }
            $total = 0.0;
            foreach ($validated['varieties'] as $index => $row) {
                $name = trim($row['variety_name']);
                $qty = (float) $row['quantity'];
                $total += $qty;
                SubsidyProgramVariety::updateOrCreate(
                    ['program_id' => $program->id, 'variety_name' => $name],
                    [
                        'unit' => $row['unit'] ?? null,
                        'bags_per_hectare' => $row['bags_per_hectare'] ?? $program->items_per_hectare,
                        'target_fca' => $row['target_fca'] ?? null,
                        'target_barangays' => $row['target_barangays'] ?? null,
                        'total_quantity' => $qty,
                        'remaining_quantity' => $hasClaims
                            ? DB::raw('remaining_quantity')
                            : $qty,
                        'sort_order' => $index,
                    ]
                );
            }
            if (! $hasClaims) {
                $program->total_quantity = $total;
                $program->remaining_quantity = $total;
                $program->save();
            }
        });

        $this->logReportAudit('seed_variety.synced', $program->id, [
            'count' => count($validated['varieties']),
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Program varieties saved.',
            'data' => $this->index()->getData(true)['data'] ?? [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Create an Active subsidy program and add varieties under that batch.',
        ], 422);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $variety = SubsidyProgramVariety::query()->findOrFail($id);
        $validated = $request->validate([
            'variety_name' => 'sometimes|string|max:120',
            'unit' => 'nullable|string|max:64',
            'bags_per_hectare' => 'sometimes|numeric|min:0.01|max:100000',
            'reorder_level' => 'nullable|numeric|min:0|max:1000000',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before updating a seed variety.');
        $variety->fill($validated)->save();

        $this->logReportAudit('seed_variety.updated', $variety->id, [
            'after' => $variety->only(['variety_name', 'bags_per_hectare']),
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Seed variety updated.',
            'data' => $variety->fresh(),
        ]);
    }

    public function restock(Request $request, string $id): JsonResponse
    {
        $variety = SubsidyProgramVariety::query()->findOrFail($id);
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01|max:1000000',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before restocking seed variety inventory.');

        $qty = (float) $validated['quantity'];
        DB::transaction(function () use ($variety, $qty) {
            $variety = SubsidyProgramVariety::where('id', $variety->id)->lockForUpdate()->first();
            $variety->total_quantity = (float) $variety->total_quantity + $qty;
            $variety->remaining_quantity = (float) $variety->remaining_quantity + $qty;
            $variety->save();
            $program = SubsidyProgram::where('id', $variety->program_id)->lockForUpdate()->first();
            if ($program) {
                $program->total_quantity = (float) $program->total_quantity + $qty;
                $program->remaining_quantity = (float) $program->remaining_quantity + $qty;
                $program->save();
            }
        });

        $this->logReportAudit('seed_variety.restocked', $variety->id, [
            'added' => $qty,
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Variety restocked.',
            'data' => $variety->fresh(),
        ]);
    }

    /**
     * Registry-based eligibility — lists Active program varieties the farmer may still claim.
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

        $exclusion = $this->exclusionReasonFor($farmer);
        if ($exclusion !== null) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This farmer is excluded from subsidies: '.$exclusion,
                'data' => ['exclusion_reason' => $exclusion],
            ], 409);
        }

        $claimedProgramIds = SubsidyBeneficiary::query()
            ->where('status', 'Claimed')
            ->where(function ($q) use ($farmer) {
                $q->where('farmer_id', $farmer->id);
                if ($farmer->rsbsa_no) {
                    $q->orWhere('farmer_rsbsa_no', $farmer->rsbsa_no);
                }
            })
            ->pluck('program_id')
            ->unique()
            ->all();

        $area = (float) ($farmer->total_farm_area_ha ?? 0);
        $farmBrgy = $farmer->farm_brgy ?: $farmer->permanent_brgy;

        $varieties = SubsidyProgramVariety::query()
            ->with('program:id,program_name,seed_class,status,unit_of_measurement,items_per_hectare,max_hectares_limit,delivery_start_date,delivery_end_date')
            ->where('remaining_quantity', '>', 0)
            ->whereHas('program', fn ($q) => $q->where('status', 'Active'))
            ->orderBy('sort_order')
            ->orderBy('variety_name')
            ->get()
            ->map(function (SubsidyProgramVariety $v) use ($claimedProgramIds, $area, $farmBrgy) {
                $program = $v->program;
                $rate = (float) ($v->bags_per_hectare ?? $program?->items_per_hectare ?? 1);
                $cap = (float) ($program?->max_hectares_limit ?? 0);
                $qty = SubsidyAllocation::bagsForArea($area, $rate, $cap > 0 ? $cap : null);
                $already = in_array($v->program_id, $claimedProgramIds, true);
                $recommended = $v->isRecommendedFor($farmBrgy);

                return [
                    'id' => $v->id,
                    'variety_name' => $v->variety_name,
                    'unit' => $v->unit ?? $program?->unit_of_measurement ?? 'Bags',
                    'bags_per_hectare' => $rate,
                    'remaining_quantity' => (float) $v->remaining_quantity,
                    'estimated_quantity' => $qty,
                    'program_id' => $v->program_id,
                    'program_name' => $program?->program_name,
                    'seed_class' => $program?->seed_class,
                    'subsidy_line' => $this->subsidyLineLabel($program?->seed_class),
                    'target_fca' => $v->target_fca,
                    'target_barangays' => $v->target_barangays ?? [],
                    'recommended' => $recommended,
                    'already_claimed' => $already,
                ];
            })
            ->sortByDesc(fn ($row) => $row['recommended'] ? 1 : 0)
            ->values();

        $priority = FarmerPriority::flags((bool) $farmer->is_pwd, $farmer->birthdate);

        return response()->json([
            'status' => 'success',
            'eligible' => true,
            'message' => 'Farmer is eligible for seed release.',
            'data' => [
                'farmer_id' => $farmer->id,
                'farmer_name' => trim($farmer->surname.', '.$farmer->first_name.' '.($farmer->middle_name ?? '')),
                'rsbsa_no' => $farmer->rsbsa_no,
                'barangay' => $farmBrgy,
                'farm_brgy' => $farmer->farm_brgy,
                'permanent_brgy' => $farmer->permanent_brgy,
                'mobile_number' => $farmer->mobile_number,
                'total_farm_size' => $area,
                'eligible_size' => $area,
                'allocated_bags' => SubsidyAllocation::bagsForArea($area, 1.0, null),
                'is_pwd' => $priority['is_pwd'],
                'is_senior' => $priority['is_senior'],
                'priority_label' => $priority['priority_label'],
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
            'variety_id' => 'required|uuid|exists:tbl_subsidy_program_varieties,id',
            'drop_off_point' => 'nullable|string|max:150',
            'fca_name' => 'nullable|string|max:150',
            'offline_sync_hash' => 'nullable|string|max:64',
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
            'failed' => $result['code'] ?? 422,
            default => 409,
        };

        return response()->json([
            'status' => in_array($result['outcome'] ?? '', ['created', 'already_claimed'], true) ? 'success' : 'error',
            'message' => $result['message'] ?? 'Claim failed.',
            'data' => $result['data'] ?? null,
        ], $code);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{outcome: string, message: string, code?: int, data?: array<string, mixed>}
     */
    public function executeClaim(array $item, ?string $technicianId = null): array
    {
        $farmer = $this->resolveFarmer($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);
        if (! $farmer) {
            return ['outcome' => 'failed', 'code' => 404, 'message' => 'No registered farmer matches that ID / RSBSA.'];
        }

        $exclusion = $this->exclusionReasonFor($farmer);
        if ($exclusion !== null) {
            return [
                'outcome' => 'failed',
                'code' => 409,
                'message' => 'This farmer is excluded from subsidies: '.$exclusion,
            ];
        }

        $varietyId = $item['variety_id'] ?? null;
        if (! $varietyId) {
            return ['outcome' => 'failed', 'code' => 422, 'message' => 'Select a seed variety to release.'];
        }

        try {
            return DB::transaction(function () use ($farmer, $varietyId, $item, $technicianId) {
                $variety = SubsidyProgramVariety::query()->lockForUpdate()->find($varietyId);
                if (! $variety) {
                    return ['outcome' => 'failed', 'code' => 404, 'message' => 'That seed variety is not available.'];
                }

                $program = SubsidyProgram::query()->lockForUpdate()->find($variety->program_id);
                if (! $program || $program->status !== 'Active') {
                    return ['outcome' => 'failed', 'code' => 400, 'message' => 'The parent subsidy program is not active.'];
                }

                $existingQuery = SubsidyBeneficiary::query()
                    ->where('program_id', $program->id)
                    ->where('status', 'Claimed')
                    ->where(function ($q) use ($farmer) {
                        $q->where('farmer_id', $farmer->id);
                        if ($farmer->rsbsa_no) {
                            $q->orWhere('farmer_rsbsa_no', $farmer->rsbsa_no);
                        }
                    });
                $existing = $existingQuery->first();

                if ($existing) {
                    return [
                        'outcome' => 'already_claimed',
                        'message' => 'This farmer already claimed seed for '.$program->program_name.'.',
                        'data' => [
                            'beneficiary_id' => $existing->id,
                            'program_id' => $program->id,
                            'program_name' => $program->program_name,
                            'variety_name' => optional($existing->variety)->variety_name,
                            'quantity' => (float) $existing->calculated_allocation,
                            'claimed_at' => $existing->claimed_at,
                        ],
                    ];
                }

                if (! empty($item['offline_sync_hash'])) {
                    $prior = SubsidyBeneficiary::query()
                        ->where('offline_sync_hash', $item['offline_sync_hash'])
                        ->first();
                    if ($prior) {
                        return [
                            'outcome' => 'already_claimed',
                            'message' => 'This offline release was already synced.',
                            'data' => [
                                'beneficiary_id' => $prior->id,
                                'variety_name' => optional($prior->variety)->variety_name,
                                'quantity' => (float) $prior->calculated_allocation,
                                'claimed_at' => $prior->claimed_at,
                            ],
                        ];
                    }
                }

                $area = (float) ($farmer->total_farm_area_ha ?? 0);
                if ($area <= 0) {
                    return ['outcome' => 'failed', 'code' => 422, 'message' => 'This farmer has no farm area on record.'];
                }

                $rate = (float) ($variety->bags_per_hectare ?? $program->items_per_hectare ?? 1);
                $cap = (float) ($program->max_hectares_limit ?? 0);
                // Smallholder minimum 1 bag; larger farms round(ha × rate); single variety only.
                $qty = (float) SubsidyAllocation::bagsForArea($area, $rate, $cap > 0 ? $cap : null);

                if ((float) $variety->remaining_quantity < $qty) {
                    return [
                        'outcome' => 'failed',
                        'code' => 409,
                        'message' => 'Not enough stock for '.$variety->variety_name.
                            ' (need '.$qty.', have '.(float) $variety->remaining_quantity.').',
                    ];
                }

                if ((float) $program->remaining_quantity < $qty) {
                    return [
                        'outcome' => 'failed',
                        'code' => 409,
                        'message' => 'Not enough program stock for '.$program->program_name.'.',
                    ];
                }

                $variety->remaining_quantity = (float) $variety->remaining_quantity - $qty;
                $variety->save();
                $program->remaining_quantity = (float) $program->remaining_quantity - $qty;
                $program->save();

                $farmBrgy = $farmer->farm_brgy ?: $farmer->permanent_brgy;
                $dropOff = trim((string) ($item['drop_off_point'] ?? '')) ?: $farmBrgy;
                $fcaName = trim((string) ($item['fca_name'] ?? '')) ?: null;

                $beneficiary = SubsidyBeneficiary::create([
                    'program_id' => $program->id,
                    'farmer_id' => $farmer->id,
                    'farmer_rsbsa_no' => $farmer->rsbsa_no,
                    'variety_id' => $variety->id,
                    'calculated_allocation' => $qty,
                    'source_farm_area' => $area,
                    'source_farm_barangay' => $farmBrgy,
                    'source_farm_municipality' => $farmer->farm_city ?: 'Echague',
                    'source_farmer_barangay' => $farmer->permanent_brgy,
                    'source_farmer_municipality' => $farmer->permanent_city,
                    'drop_off_point' => $dropOff,
                    'fca_name' => $fcaName,
                    'offline_sync_hash' => $item['offline_sync_hash'] ?? null,
                    'status' => 'Claimed',
                    'selection_mode' => 'field_release',
                    'claimed_at' => now(),
                    'claimed_by' => $technicianId ?? Auth::id(),
                    'override_justification' => $item['override_justification'] ?? null,
                    'override_reason_code' => $item['override_reason_code'] ?? null,
                ]);

                return [
                    'outcome' => 'created',
                    'message' => 'Released '.$qty.' '.($variety->unit ?? $program->unit_of_measurement).
                        ' of '.$variety->variety_name.' ('.$this->subsidyLineLabel($program->seed_class).').',
                    'data' => [
                        'beneficiary_id' => $beneficiary->id,
                        'farmer_id' => $farmer->id,
                        'farmer_name' => trim($farmer->surname.', '.$farmer->first_name),
                        'barangay' => $farmBrgy,
                        'drop_off_point' => $dropOff,
                        'fca_name' => $fcaName,
                        'program_id' => $program->id,
                        'program_name' => $program->program_name,
                        'seed_class' => $program->seed_class,
                        'subsidy_line' => $this->subsidyLineLabel($program->seed_class),
                        'variety_id' => $variety->id,
                        'variety_name' => $variety->variety_name,
                        'quantity' => $qty,
                        'unit' => $variety->unit ?? $program->unit_of_measurement,
                        'variety_remaining' => (float) $variety->remaining_quantity,
                        'claimed_at' => $beneficiary->claimed_at,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            // Unique constraint race: farmer already claimed this program.
            if (Str::contains($e->getMessage(), ['subsidy_program_farmer_unique', 'offline_sync_hash'])) {
                return [
                    'outcome' => 'already_claimed',
                    'message' => 'This farmer already claimed seed for this subsidy cycle.',
                ];
            }

            return ['outcome' => 'failed', 'code' => 500, 'message' => $e->getMessage() ?: 'Could not save the seed release.'];
        }
    }

    private function subsidyLineLabel(?string $seedClass): string
    {
        if ($seedClass === 'Inbred') {
            return 'RCEF';
        }
        if ($seedClass === 'Hybrid') {
            return 'Hybrid';
        }

        return $seedClass ?: 'Seed';
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

    /**
     * Stored masterlist exclusion, or a disqualifying remark typed on the farmer record.
     */
    private function exclusionReasonFor(Farmer $farmer): ?string
    {
        if (! empty($farmer->subsidy_exclusion_reason)) {
            return (string) $farmer->subsidy_exclusion_reason;
        }

        return SubsidyExclusionRules::matchedLabel($farmer->enlistment_remarks);
    }
}
