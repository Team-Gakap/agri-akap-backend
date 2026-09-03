<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\HarvestLog;
use App\Traits\AssertsPlotAreaCap;
use App\Traits\LogsReportAudit;
use App\Traits\ResolvesEncodingBarangay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HarvestLogController extends Controller
{
    use AssertsPlotAreaCap;
    use LogsReportAudit;
    use ResolvesEncodingBarangay;
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barangay' => ['nullable', 'string'],
            'crop_type' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = HarvestLog::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,ext_name,birthdate,permanent_house_no,permanent_street,permanent_brgy,permanent_city,permanent_province',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->orderByDesc('date_harvested')
            ->orderByDesc('created_at');

        $this->applyEncodingBarangayScope($query, $request);

        if (! empty($validated['crop_type'])) {
            $query->where('crop_type', $validated['crop_type']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('date_harvested', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('date_harvested', '<=', $validated['date_to']);
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
            'area_harvested' => ['required', 'numeric', 'min:0'],
            'total_yield' => ['required', 'numeric', 'min:0'],
            'yield_unit' => ['nullable', Rule::in(['Metric Tons'])],
            'date_harvested' => ['required', 'date'],
            'farm_location' => ['nullable', 'string', 'max:255'],
            'barangay_name' => ['nullable', 'string', 'max:255'],
        ]);

        $farmer = Farmer::findOrFail($validated['farmer_id']);
        $user = $request->user();

        $barangayResult = $this->resolveEncodingBarangay($request, $farmer);
        if ($barangayResult instanceof JsonResponse) {
            return $barangayResult;
        }
        $encodingBarangay = $barangayResult['barangay'];

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
            (float) $validated['area_harvested'],
            'Area harvested',
        );
        if ($areaError) {
            return $areaError;
        }

        if (! empty($validated['id'])) {
            $existing = HarvestLog::find($validated['id']);
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Harvest already recorded.',
                    'data' => $existing->load('farmer', 'farmPlot'),
                    'duplicate' => true,
                ]);
            }
        }

        $log = HarvestLog::create([
            'id' => $validated['id'] ?? null,
            'client_id' => $validated['id'] ?? null,
            'farmer_id' => $validated['farmer_id'],
            'farm_plot_id' => $validated['farm_plot_id'] ?? null,
            'technician_id' => $user->id,
            'crop_type' => $validated['crop_type'],
            'variety' => $validated['variety'],
            'area_harvested' => $validated['area_harvested'],
            'total_yield' => $validated['total_yield'],
            'yield_unit' => 'Metric Tons',
            'date_harvested' => $validated['date_harvested'],
            'farm_location' => $validated['farm_location'] ?? $encodingBarangay ?? $farmer->permanent_brgy,
        ]);

        $this->logReportAudit('harvest_log.created', $log, [
            'after' => $log->only(['farmer_id', 'crop_type', 'variety', 'area_harvested', 'total_yield', 'date_harvested']),
            'record_code' => $farmer->rsbsa_no,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Harvest record saved.',
            'data' => $log->load('farmer', 'farmPlot'),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $log = HarvestLog::with('farmer')->findOrFail($id);
        $denied = $this->assertCanDeleteEncodedRecord($request, $log->farmer);
        if ($denied) {
            return $denied;
        }

        $validated = $request->validate([
            'farm_plot_id' => ['nullable', 'uuid', 'exists:farm_plots,id'],
            'crop_type' => ['sometimes', 'required', 'string', 'max:64'],
            'variety' => ['sometimes', 'required', 'string', 'max:128'],
            'area_harvested' => ['sometimes', 'required', 'numeric', 'min:0'],
            'total_yield' => ['sometimes', 'required', 'numeric', 'min:0'],
            'yield_unit' => ['nullable', Rule::in(['Metric Tons'])],
            'date_harvested' => ['sometimes', 'required', 'date'],
            'farm_location' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['area_harvested'])) {
            $areaError = $this->assertAreaWithinPlot(
                $validated['farm_plot_id'] ?? $log->farm_plot_id,
                (float) $validated['area_harvested'],
                'Area harvested',
            );
            if ($areaError) {
                return $areaError;
            }
        }

        $before = $log->only(array_keys($validated));
        $log->update($validated);
        $this->logReportAudit('harvest_log.updated', $log, [
            'before' => $before,
            'after' => $log->fresh()->only(array_keys($validated)),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Harvest record updated.',
            'data' => $log->fresh()->load('farmer', 'farmPlot'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $log = HarvestLog::with('farmer')->findOrFail($id);
        $denied = $this->assertCanDeleteEncodedRecord($request, $log->farmer);
        if ($denied) {
            return $denied;
        }

        $log->delete();
        $this->logReportAudit('harvest_log.deleted', $log);

        return response()->json([
            'status' => 'success',
            'message' => 'Harvest record removed.',
        ]);
    }
}
