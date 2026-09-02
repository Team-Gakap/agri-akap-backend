<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\StandingCropLog;
use App\Traits\AssertsPlotAreaCap;
use App\Traits\LogsReportAudit;
use App\Traits\ResolvesEncodingBarangay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StandingCropLogController extends Controller
{
    use AssertsPlotAreaCap;
    use LogsReportAudit;
    use ResolvesEncodingBarangay;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barangay' => ['nullable', 'string'],
            'crop_type' => ['nullable', 'string'],
            'growth_stage' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $user = $request->user();
        $query = StandingCropLog::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,ext_name,birthdate,permanent_house_no,permanent_street,permanent_brgy,permanent_city,permanent_province',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->orderByDesc('est_harvest_date')
            ->orderByDesc('created_at');

        if ($user->role === 'barangay_official' && $user->assigned_barangay) {
            $query->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $user->assigned_barangay));
        } elseif (! empty($validated['barangay'])) {
            $query->whereHas('farmer', fn ($f) => $f->where('permanent_brgy', $validated['barangay']));
        }

        if (! empty($validated['crop_type'])) {
            $query->where('crop_type', $validated['crop_type']);
        }
        if (! empty($validated['growth_stage'])) {
            $query->where('growth_stage', $validated['growth_stage']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate((int) ($validated['per_page'] ?? 200)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'uuid'],
            'farmer_id' => ['required', 'uuid', 'exists:farmers,id'],
            'farm_plot_id' => ['nullable', 'uuid', 'exists:farm_plots,id'],
            'crop_type' => ['required', 'string', 'max:64'],
            'variety' => ['required', 'string', 'max:128'],
            'area_ha' => ['required', 'numeric', 'min:0'],
            'growth_stage' => ['nullable', 'string', 'max:64'],
            'est_harvest_date' => ['required', 'date'],
            'farm_location' => ['nullable', 'string', 'max:255'],
        ]);

        $farmer = Farmer::findOrFail($validated['farmer_id']);
        $user = $request->user();

        if ($user->role === 'barangay_official' && $user->assigned_barangay
            && $farmer->permanent_brgy !== $user->assigned_barangay) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only encode farmers from your assigned barangay.',
            ], 403);
        }

        if (! empty($validated['farm_plot_id'])) {
            $plot = FarmPlot::findOrFail($validated['farm_plot_id']);
            if ($plot->farmer_id !== $farmer->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Selected farm plot does not belong to this farmer.',
                ], 422);
            }
        }

        $areaError = $this->assertAreaWithinPlot(
            $validated['farm_plot_id'] ?? null,
            (float) $validated['area_ha'],
            'Standing crop area',
        );
        if ($areaError) {
            return $areaError;
        }

        if (! empty($validated['id'])) {
            $existing = StandingCropLog::find($validated['id']);
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Standing crop already recorded.',
                    'data' => $existing->load('farmer', 'farmPlot'),
                    'duplicate' => true,
                ]);
            }
        }

        $log = StandingCropLog::create([
            'id' => $validated['id'] ?? null,
            'client_id' => $validated['id'] ?? null,
            'farmer_id' => $validated['farmer_id'],
            'farm_plot_id' => $validated['farm_plot_id'] ?? null,
            'technician_id' => $user->id,
            'crop_type' => $validated['crop_type'],
            'variety' => $validated['variety'],
            'area_ha' => $validated['area_ha'],
            'growth_stage' => $validated['growth_stage'] ?? 'Vegetative',
            'est_harvest_date' => $validated['est_harvest_date'],
            'farm_location' => $validated['farm_location'] ?? $farmer->permanent_brgy,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Standing crop saved.',
            'data' => $log->load('farmer', 'farmPlot'),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $log = StandingCropLog::with('farmer')->findOrFail($id);
        $denied = $this->assertCanDeleteEncodedRecord($request, $log->farmer);
        if ($denied) {
            return $denied;
        }

        $validated = $request->validate([
            'farm_plot_id' => ['nullable', 'uuid', 'exists:farm_plots,id'],
            'crop_type' => ['sometimes', 'required', 'string', 'max:64'],
            'variety' => ['sometimes', 'required', 'string', 'max:128'],
            'area_ha' => ['sometimes', 'required', 'numeric', 'min:0'],
            'growth_stage' => ['nullable', 'string', 'max:64'],
            'est_harvest_date' => ['sometimes', 'required', 'date'],
            'farm_location' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['area_ha'])) {
            $areaError = $this->assertAreaWithinPlot(
                $validated['farm_plot_id'] ?? $log->farm_plot_id,
                (float) $validated['area_ha'],
                'Standing crop area',
            );
            if ($areaError) {
                return $areaError;
            }
        }

        $before = $log->only(array_keys($validated));
        $log->update($validated);
        $this->logReportAudit('standing_crop_log.updated', $log, [
            'before' => $before,
            'after' => $log->fresh()->only(array_keys($validated)),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Standing crop record updated.',
            'data' => $log->fresh()->load('farmer', 'farmPlot'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $log = StandingCropLog::with('farmer')->findOrFail($id);
        $denied = $this->assertCanDeleteEncodedRecord($request, $log->farmer);
        if ($denied) {
            return $denied;
        }

        $log->delete();
        $this->logReportAudit('standing_crop_log.deleted', $log);

        return response()->json([
            'status' => 'success',
            'message' => 'Standing crop record removed.',
        ]);
    }
}
