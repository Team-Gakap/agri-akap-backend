<?php

namespace App\Http\Controllers;

use App\Models\Distribution;
use App\Models\Farmer;
use App\Models\Program;
use App\Http\Requests\ClaimSubsidyRequest;
use App\Traits\DecodesBase64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DistributionController extends Controller
{
    use DecodesBase64Image;

    /**
     * Verify eligibility, calculate allocation, and process the subsidy claim.
     */
    public function processClaim(ClaimSubsidyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $technicianId = $request->user()->id;

        $result = $this->executeClaim($validated, $technicianId);

        return response()->json($result['body'], $result['http']);
    }

    /**
     * No-write eligibility check for the Scan tab. Runs the same guards as
     * executeClaim() (program status, double-dipping, allocation math,
     * inventory sufficiency) and returns an allocation preview WITHOUT
     * mutating inventory or creating a distribution record.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farmer_id' => 'required|uuid|exists:farmers,id',
            'program_id' => 'required|uuid|exists:programs,id',
        ]);

        $program = Program::find($validated['program_id']);

        if (!$program->is_active || $program->end_date < now()) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'This program is inactive or has already ended.',
            ], 400);
        }

        $existingClaim = Distribution::where('farmer_id', $validated['farmer_id'])
            ->where('program_id', $program->id)
            ->first();

        if ($existingClaim) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'FRAUD ALERT: This farmer has already claimed their subsidy for this program.',
                'data' => [
                    'claimed_at' => $existingClaim->created_at->format('M d, Y h:i A'),
                ],
            ], 409);
        }

        $farmer = Farmer::with('farmPlots')->findOrFail($validated['farmer_id']);
        $totalHectares = $farmer->farmPlots->sum('size_ha');

        if ($totalHectares <= 0) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'Farmer has no valid farm plots registered. Cannot allocate subsidy.',
            ], 400);
        }

        $eligibleHectares = min($totalHectares, $program->max_hectare_cap);
        $quantityToDispense = floor($eligibleHectares * $program->per_hectare_allocation);

        if ($quantityToDispense < 1) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'Calculated allocation is less than 1 unit. Farmer farm size does not meet minimum requirements.',
            ], 400);
        }

        if ($program->remaining_quantity < $quantityToDispense) {
            return response()->json([
                'status' => 'error',
                'eligible' => false,
                'message' => 'Insufficient inventory. The system calculated ' . $quantityToDispense . ' units, but only ' . $program->remaining_quantity . ' remain.',
            ], 400);
        }

        // Fall back to the primary plot's coordinates for best-effort geo-tag.
        $primaryPlot = $farmer->farmPlots->first();

        return response()->json([
            'status' => 'success',
            'eligible' => true,
            'message' => 'Verification passed. Farmer is eligible to claim.',
            'data' => [
                'farmer_id' => $farmer->id,
                'program_id' => $program->id,
                'farmer_name' => trim($farmer->first_name . ' ' . $farmer->surname),
                'mobile_number' => $farmer->mobile_number,
                'item_released' => $program->name,
                'unit' => $program->unit_of_measurement,
                'total_farm_size' => (float) $totalHectares,
                'eligible_size' => (float) $eligibleHectares,
                'quantity' => (int) $quantityToDispense,
                'inventory_remaining' => (int) $program->remaining_quantity,
                'plot_lat' => $primaryPlot->latitude ?? null,
                'plot_long' => $primaryPlot->longitude ?? null,
            ],
        ]);
    }

    /**
     * Core claim engine. Returns a structured result usable by both the
     * live HTTP endpoint and the offline bulk-sync engine.
     *
     * @return array{http:int, outcome:string, body:array}
     *   outcome is one of: synced | duplicate | failed
     */
    public function executeClaim(array $validated, string $technicianId): array
    {
        try {
            $result = DB::transaction(function () use ($validated, $technicianId) {

                // Idempotency guard: a client-generated UUID that already
                // exists means this offline record was already synced.
                if (!empty($validated['client_id'])) {
                    $already = Distribution::find($validated['client_id']);
                    if ($already) {
                        return $this->claimResult(200, 'duplicate', [
                            'status' => 'success',
                            'message' => 'This distribution was already synced.',
                            'data' => $already,
                        ]);
                    }
                }

                // 1. Lock the Program row to prevent inventory race conditions.
                $program = Program::where('id', $validated['program_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$program) {
                    return $this->claimResult(404, 'failed', [
                        'status' => 'error',
                        'message' => 'Program not found.',
                    ]);
                }

                // 2. Validate Program Status
                if (!$program->is_active || $program->end_date < now()) {
                    return $this->claimResult(400, 'failed', [
                        'status' => 'error',
                        'message' => 'This program is inactive or has already ended.',
                    ]);
                }

                // 3. Double-Dipping Check
                $existingClaim = Distribution::where('farmer_id', $validated['farmer_id'])
                    ->where('program_id', $program->id)
                    ->first();

                if ($existingClaim) {
                    // Treat as a resolved duplicate for offline sync so the
                    // client can safely clear the queued item.
                    return $this->claimResult(409, 'duplicate', [
                        'status' => 'error',
                        'message' => 'FRAUD ALERT: This farmer has already claimed their subsidy for this program.',
                        'data' => [
                            'claimed_at' => optional($existingClaim->created_at)->format('M d, Y h:i A'),
                        ],
                    ]);
                }

                // 4. Fetch Farmer and Calculate Eligible Hectares
                $farmer = Farmer::with('farmPlots')->findOrFail($validated['farmer_id']);
                $totalHectares = $farmer->farmPlots->sum('size_ha');

                if ($totalHectares <= 0) {
                    return $this->claimResult(400, 'failed', [
                        'status' => 'error',
                        'message' => 'Farmer has no valid farm plots registered. Cannot allocate subsidy.',
                    ]);
                }

                $eligibleHectares = min($totalHectares, $program->max_hectare_cap);
                $quantityToDispense = floor($eligibleHectares * $program->per_hectare_allocation);

                if ($quantityToDispense < 1) {
                    return $this->claimResult(400, 'failed', [
                        'status' => 'error',
                        'message' => 'Calculated allocation is less than 1 unit. Farmer farm size does not meet minimum requirements.',
                    ]);
                }

                // 5. Inventory Verification
                if ($program->remaining_quantity < $quantityToDispense) {
                    return $this->claimResult(400, 'failed', [
                        'status' => 'error',
                        'message' => 'Insufficient inventory. The system calculated ' . $quantityToDispense . ' units, but only ' . $program->remaining_quantity . ' remain.',
                    ]);
                }

                // 6. Process the Transaction
                $program->remaining_quantity -= $quantityToDispense;
                $program->save();

                // Persist the photo voucher (best-effort; a decode failure
                // simply leaves the path null rather than aborting the claim).
                $photoPath = $this->storeBase64Image(
                    $validated['photo_proof_base64'] ?? null,
                    'distributions'
                );

                $distribution = Distribution::create([
                    'id' => $validated['client_id'] ?? null,
                    'program_id' => $program->id,
                    'farmer_id' => $farmer->id,
                    'distributed_by' => $technicianId,
                    'quantity_claimed' => $quantityToDispense,
                    'item_released' => $program->name,
                    'geo_tag_lat' => $validated['geo_tag_lat'] ?? null,
                    'geo_tag_long' => $validated['geo_tag_long'] ?? null,
                    'photo_proof_path' => $photoPath,
                    'status' => 'claimed',
                    'device_id' => $validated['device_id'] ?? null,
                    'claimed_at' => $validated['claimed_at'] ?? now(),
                ]);

                return $this->claimResult(200, 'synced', [
                    'status' => 'success',
                    'message' => 'Verification Passed. Subsidy successfully claimed.',
                    'data' => [
                        'id' => $distribution->id,
                        'farmer_name' => $farmer->first_name . ' ' . $farmer->surname,
                        'item_released' => $program->name,
                        'total_farm_size' => $totalHectares . ' ha',
                        'eligible_size_capped' => $eligibleHectares . ' ha',
                        'quantity_dispensed' => $quantityToDispense . ' ' . $program->unit_of_measurement,
                        'inventory_remaining' => $program->remaining_quantity,
                    ],
                ]);
            });

            return $result;
        } catch (\Throwable $e) {
            Log::error('Distribution claim failed: ' . $e->getMessage());

            return $this->claimResult(500, 'failed', [
                'status' => 'error',
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'A critical error occurred while processing the claim.',
            ]);
        }
    }

    private function claimResult(int $http, string $outcome, array $body): array
    {
        return ['http' => $http, 'outcome' => $outcome, 'body' => $body];
    }
}
