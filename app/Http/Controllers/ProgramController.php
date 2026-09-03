<?php

namespace App\Http\Controllers;

use App\Models\Distribution;
use App\Models\Program;
use App\Http\Requests\StoreProgramRequest;
use App\Support\AuditRemarks;
use App\Traits\LogsReportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgramController extends Controller
{
    use LogsReportAudit;
    /**
     * Display current active and past assistance programs.
     */
    public function index(Request $request): JsonResponse
    {
        $programs = Program::withCount('distributions')
            ->orderBy('created_at', 'desc')
            ->when($request->boolean('active_only'), fn ($q) =>
                $q->where('is_active', true)->where('end_date', '>=', now())
            )
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'message' => 'Subsidies and program registries loaded.',
            'data' => $programs,
        ], 200);
    }

    /**
     * Initialize a new subsidy distribution campaign with protected inventory allocations.
     */
    public function store(StoreProgramRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $validatedData['remaining_quantity'] = $validatedData['total_quantity'];
        $validatedData['is_active'] = true;

        $program = Program::create($validatedData);

        $this->logReportAudit('program.created', $program, [
            'after' => $program->only(['name', 'type', 'total_quantity', 'remaining_quantity', 'is_active']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Subsidy campaign initialized and inventory secured.',
            'data' => $program,
        ], 201);
    }

    /**
     * Show a specific program profile with real-time inventory and distribution summary.
     */
    public function show(string $id): JsonResponse
    {
        $program = Program::withCount('distributions')->findOrFail($id);

        $summary = Distribution::where('program_id', $id)
            ->selectRaw('SUM(quantity_claimed) as total_dispensed, COUNT(*) as beneficiaries')
            ->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Program metadata fetched.',
            'data' => array_merge($program->toArray(), [
                'total_dispensed' => $summary->total_dispensed ?? 0,
                'beneficiaries' => $summary->beneficiaries ?? 0,
            ]),
        ], 200);
    }

    /**
     * Deactivate a program (admin only). Does not delete — preserves audit trail.
     */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        $program = Program::findOrFail($id);

        if (!$program->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Program is already inactive.',
            ], 409);
        }

        $program->update(['is_active' => false]);
        $this->logReportAudit('program.deactivated', $program, [
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Program has been deactivated. Existing distributions are preserved.',
            'data' => $program->fresh(),
        ]);
    }

    /**
     * Log an incoming regional delivery (admin only). Adds the delivered units
     * to both the lifetime total and the currently available stock.
     */
    public function restock(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity_added' => 'required|integer|min:1',
        ]);
        $remarks = AuditRemarks::require($request, 'A justification is required before restocking a program.');
        $before = Program::query()->findOrFail($id)->only(['total_quantity', 'remaining_quantity']);

        $program = DB::transaction(function () use ($id, $validated) {
            $program = Program::where('id', $id)->lockForUpdate()->firstOrFail();
            $program->total_quantity += $validated['quantity_added'];
            $program->remaining_quantity += $validated['quantity_added'];
            $program->save();

            return $program;
        });

        $this->logReportAudit('program.restocked', $program, [
            'before' => $before,
            'after' => $program->only(['total_quantity', 'remaining_quantity']),
            'remarks' => $remarks,
            'quantity_added' => $validated['quantity_added'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Delivery logged. {$validated['quantity_added']} {$program->unit_of_measurement} added to stock.",
            'data' => $program->fresh(),
        ]);
    }

    /**
     * Update stock-management configuration (admin only): minimum reorder
     * threshold and the barangays targeted by the active distribution cycle.
     */
    public function updateConfig(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reorder_level' => 'nullable|integer|min:0',
            'target_barangays' => 'nullable|array',
            'target_barangays.*' => 'string|max:255',
        ]);

        $program = Program::findOrFail($id);
        $remarks = AuditRemarks::require($request, 'A justification is required before changing program stock settings.');
        $before = $program->only(['reorder_level', 'target_barangays']);
        $program->update([
            'reorder_level' => $validated['reorder_level'] ?? null,
            'target_barangays' => $validated['target_barangays'] ?? null,
        ]);

        $this->logReportAudit('program.config_updated', $program, [
            'before' => $before,
            'after' => $program->fresh()->only(['reorder_level', 'target_barangays']),
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stock settings updated.',
            'data' => $program->fresh(),
        ]);
    }
}
