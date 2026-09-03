<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\PlantingLog;
use App\Traits\AssertsPlotAreaCap;
use App\Traits\LogsReportAudit;
use App\Traits\ResolvesEncodingBarangay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlantingLogController extends Controller
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

        $query = PlantingLog::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,ext_name,birthdate,permanent_house_no,permanent_street,permanent_brgy,permanent_city,permanent_province',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->orderByDesc('date_planted');

        $this->applyEncodingBarangayScope($query, $request);

        if (! empty($validated['crop_type'])) {
            $query->where('crop_type', $validated['crop_type']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('date_planted', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('date_planted', '<=', $validated['date_to']);
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
            'area_planted' => ['required', 'numeric', 'min:0'],
            'date_planted' => ['required', 'date'],
            'status' => ['nullable', 'string', 'max:64'],
            'water_source' => ['nullable', 'string', 'max:64'],
            'farm_location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
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
            if (strcasecmp((string) $plot->commodity, (string) $validated['crop_type']) !== 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Selected plot is {$plot->commodity}, but this form is for {$validated['crop_type']} only.",
                ], 422);
            }
        } else {
            $hasCropPlot = FarmPlot::where('farmer_id', $farmer->id)
                ->whereRaw('LOWER(commodity) = ?', [strtolower($validated['crop_type'])])
                ->exists();
            if (! $hasCropPlot) {
                return response()->json([
                    'status' => 'error',
                    'message' => "This farmer has no {$validated['crop_type']} farm plot. Switch crop type or pick another farmer.",
                ], 422);
            }
        }

        $areaError = $this->assertAreaWithinPlot(
            $validated['farm_plot_id'] ?? null,
            (float) $validated['area_planted'],
            'Area planted',
        );
        if ($areaError) {
            return $areaError;
        }

        if (! empty($validated['id'])) {
            $existing = PlantingLog::find($validated['id']);
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Planting log already recorded.',
                    'data' => $existing->load('farmer', 'farmPlot'),
                    'duplicate' => true,
                ]);
            }
        }

        $log = PlantingLog::create([
            'id' => $validated['id'] ?? null,
            'client_id' => $validated['id'] ?? null,
            'farmer_id' => $validated['farmer_id'],
            'farm_plot_id' => $validated['farm_plot_id'] ?? null,
            'technician_id' => $user->id,
            'crop_type' => $validated['crop_type'],
            'variety' => $validated['variety'],
            'area_planted' => $validated['area_planted'],
            'date_planted' => $validated['date_planted'],
            'status' => $validated['status'] ?? 'Active',
            'water_source' => $validated['water_source'] ?? null,
            'farm_location' => $validated['farm_location'] ?? $encodingBarangay ?? $farmer->permanent_brgy,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $this->logReportAudit('planting_log.created', $log, [
            'after' => $log->only(['farmer_id', 'crop_type', 'variety', 'area_planted', 'date_planted']),
            'record_code' => $farmer->rsbsa_no,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Planting log saved.',
            'data' => $log->load('farmer', 'farmPlot'),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $log = PlantingLog::with('farmer')->findOrFail($id);
        $denied = $this->assertCanDeleteEncodedRecord($request, $log->farmer);
        if ($denied) {
            return $denied;
        }

        $validated = $request->validate([
            'farm_plot_id' => ['nullable', 'uuid', 'exists:farm_plots,id'],
            'crop_type' => ['sometimes', 'required', 'string', 'max:64'],
            'variety' => ['sometimes', 'required', 'string', 'max:128'],
            'area_planted' => ['sometimes', 'required', 'numeric', 'min:0'],
            'date_planted' => ['sometimes', 'required', 'date'],
            'status' => ['nullable', 'string', 'max:64'],
            'water_source' => ['nullable', 'string', 'max:64'],
            'farm_location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($validated['area_planted'])) {
            $areaError = $this->assertAreaWithinPlot(
                $validated['farm_plot_id'] ?? $log->farm_plot_id,
                (float) $validated['area_planted'],
                'Area planted',
            );
            if ($areaError) {
                return $areaError;
            }
        }

        $before = $log->only(array_keys($validated));
        $log->update($validated);
        $this->logReportAudit('planting_log.updated', $log, [
            'before' => $before,
            'after' => $log->fresh()->only(array_keys($validated)),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Planting log updated.',
            'data' => $log->fresh()->load('farmer', 'farmPlot'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $log = PlantingLog::with('farmer')->findOrFail($id);
        $denied = $this->assertCanDeleteEncodedRecord($request, $log->farmer);
        if ($denied) {
            return $denied;
        }

        $log->delete();
        $this->logReportAudit('planting_log.deleted', $log);

        return response()->json([
            'status' => 'success',
            'message' => 'Planting log removed.',
        ]);
    }
}
