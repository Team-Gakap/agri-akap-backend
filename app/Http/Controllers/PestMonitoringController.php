<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\PestMonitoring;
use App\Traits\AssertsPlotAreaCap;
use App\Traits\DecodesBase64Image;
use App\Traits\LogsReportAudit;
use App\Traits\ResolvesEncodingBarangay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PestMonitoringController extends Controller
{
    use AssertsPlotAreaCap;
    use DecodesBase64Image;
    use LogsReportAudit;
    use ResolvesEncodingBarangay;

    public function guidelines(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => config('pest_guidelines.by_crop', []),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barangay' => ['nullable', 'string'],
            'crop_type' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'pending_field' => ['nullable', 'in:true,false,1,0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = PestMonitoring::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,ext_name,birthdate,permanent_house_no,permanent_street,permanent_brgy,permanent_city,permanent_province',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->orderByDesc('date_of_inspection')
            ->orderByDesc('created_at');

        $this->applyEncodingBarangayScope($query, $request);

        if ($request->boolean('pending_field')) {
            $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('photo_path');
            });
        }

        if (! empty($validated['crop_type'])) {
            $query->where('crop', $validated['crop_type']);
        }
        if (! empty($validated['date_from'])) {
            $query->where(function ($q) use ($validated) {
                $q->whereDate('date_of_inspection', '>=', $validated['date_from'])
                    ->orWhere(function ($q2) use ($validated) {
                        $q2->whereNull('date_of_inspection')
                            ->whereDate('created_at', '>=', $validated['date_from']);
                    });
            });
        }
        if (! empty($validated['date_to'])) {
            $query->where(function ($q) use ($validated) {
                $q->whereDate('date_of_inspection', '<=', $validated['date_to'])
                    ->orWhere(function ($q2) use ($validated) {
                        $q2->whereNull('date_of_inspection')
                            ->whereDate('created_at', '<=', $validated['date_to']);
                    });
            });
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 200));

        return response()->json([
            'status' => 'success',
            'data' => $paginator,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $row = PestMonitoring::query()
            ->with([
                'farmer:id,rsbsa_no,surname,first_name,middle_name,ext_name,permanent_brgy,mobile_number',
                'farmPlot:id,location_brgy,commodity,size_ha',
            ])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $row,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'uuid'],
            'farmer_id' => ['required', 'uuid', 'exists:farmers,id'],
            'farm_plot_id' => ['nullable', 'uuid', 'exists:farm_plots,id'],
            'crop' => ['required', 'string', 'max:64'],
            'crop_stage' => ['nullable', 'string', 'max:64'],
            'variety' => ['nullable', 'string', 'max:128'],
            'area_planted' => ['required', 'numeric', 'min:0'],
            'days_after_planting' => ['required', 'integer', 'min:0', 'max:400'],
            'area_damage_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'damage_by' => ['required', 'string', 'max:255'],
            'date_of_inspection' => ['required', 'date'],
            'farm_location' => ['nullable', 'string', 'max:255'],
            'photo_base64' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'barangay_name' => ['nullable', 'string', 'max:255'],
            'is_outbreak' => ['nullable', 'boolean'],
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
            if (strcasecmp((string) $plot->commodity, (string) $validated['crop']) !== 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Selected plot is {$plot->commodity}, but this form is for {$validated['crop']} only.",
                ], 422);
            }
        } else {
            $hasCropPlot = FarmPlot::where('farmer_id', $farmer->id)
                ->whereRaw('LOWER(commodity) = ?', [strtolower($validated['crop'])])
                ->exists();
            if (! $hasCropPlot) {
                return response()->json([
                    'status' => 'error',
                    'message' => "This farmer has no {$validated['crop']} farm plot. Switch crop type or pick another farmer.",
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
            $existing = PestMonitoring::find($validated['id']);
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Pest inspection already recorded.',
                    'data' => $existing->load('farmer', 'farmPlot'),
                    'duplicate' => true,
                ]);
            }
        }

        $photoPath = null;
        if (! empty($validated['photo_base64'])) {
            $photoPath = $this->storeBase64Image($validated['photo_base64'], 'pest-monitoring');
        }

        $pct = (float) $validated['area_damage_pct'];
        $severity = $pct >= 60 ? 'High' : ($pct >= 30 ? 'Moderate' : 'Low');

        $row = PestMonitoring::create([
            'id' => $validated['id'] ?? null,
            'client_id' => $validated['id'] ?? null,
            'farmer_id' => $validated['farmer_id'],
            'farm_plot_id' => $validated['farm_plot_id'] ?? null,
            'technician_id' => $user->id,
            'crop' => $validated['crop'],
            'crop_stage' => $validated['crop_stage'] ?? null,
            'variety' => $validated['variety'] ?? null,
            'area_planted' => $validated['area_planted'],
            'days_after_planting' => $validated['days_after_planting'],
            'area_damage_pct' => $pct,
            'farm_location' => $validated['farm_location'] ?? $encodingBarangay ?? $farmer->permanent_brgy,
            'date_of_inspection' => $validated['date_of_inspection'],
            'pest_name' => $validated['damage_by'],
            'incidence' => (int) round($pct),
            'severity' => $severity,
            'is_outbreak' => (bool) ($validated['is_outbreak'] ?? false),
            'photo_path' => $photoPath,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

        $this->logReportAudit('pest_monitoring.created', $row, [
            'after' => $row->only(['farmer_id', 'crop', 'pest_name', 'area_damage_pct', 'date_of_inspection', 'severity']),
            'record_code' => $farmer->rsbsa_no,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pest inspection saved.',
            'data' => $row->load('farmer', 'farmPlot'),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = PestMonitoring::with('farmer')->findOrFail($id);
        $isPending = ! $row->latitude || ! $row->photo_path;
        $denied = $this->assertCanEditPending($request, $row->farmer, $isPending);
        if ($denied) {
            return $denied;
        }

        $validated = $request->validate([
            'farm_plot_id' => ['nullable', 'uuid', 'exists:farm_plots,id'],
            'crop' => ['sometimes', 'required', 'string', 'max:64'],
            'crop_stage' => ['nullable', 'string', 'max:64'],
            'variety' => ['nullable', 'string', 'max:128'],
            'area_planted' => ['sometimes', 'required', 'numeric', 'min:0'],
            'days_after_planting' => ['sometimes', 'required', 'integer', 'min:0', 'max:400'],
            'area_damage_pct' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'damage_by' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_inspection' => ['sometimes', 'required', 'date'],
            'farm_location' => ['nullable', 'string', 'max:255'],
            'is_outbreak' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['area_planted'])) {
            $areaError = $this->assertAreaWithinPlot(
                $validated['farm_plot_id'] ?? $row->farm_plot_id,
                (float) $validated['area_planted'],
                'Area planted',
            );
            if ($areaError) {
                return $areaError;
            }
        }

        if (array_key_exists('damage_by', $validated)) {
            $validated['pest_name'] = $validated['damage_by'];
            unset($validated['damage_by']);
        }

        if (isset($validated['area_damage_pct'])) {
            $pct = (float) $validated['area_damage_pct'];
            $validated['incidence'] = (int) round($pct);
            $validated['severity'] = $pct >= 60 ? 'High' : ($pct >= 30 ? 'Moderate' : 'Low');
        }

        $before = $row->only(array_keys($validated));
        $row->update($validated);
        $this->logReportAudit('pest_monitoring.updated', $row, [
            'before' => $before,
            'after' => $row->fresh()->only(array_keys($validated)),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pest inspection updated.',
            'data' => $row->fresh()->load('farmer', 'farmPlot'),
        ]);
    }

    public function fieldValidate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'photo_base64' => 'required|string',
            'pest_name' => 'nullable|string|max:255',
            'incidence' => 'nullable|numeric|min:0|max:100',
            'severity' => 'nullable|string|max:32',
            'advisory' => 'nullable|string|max:2000',
            'item_distributed' => 'nullable|string|max:255',
            'quantity' => 'nullable|string|max:64',
            'is_outbreak' => 'nullable|boolean',
        ]);

        $row = PestMonitoring::findOrFail($id);
        $path = $this->storeBase64Image($validated['photo_base64'], 'pest-monitoring');
        if ($path === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'The photo evidence could not be decoded. Please recapture.',
            ], 422);
        }

        $incidence = array_key_exists('incidence', $validated)
            ? (int) round((float) $validated['incidence'])
            : $row->incidence;

        $row->update([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'photo_path' => $path,
            'technician_id' => $request->user()->id,
            'pest_name' => $validated['pest_name'] ?? $row->pest_name,
            'incidence' => $incidence,
            'severity' => $validated['severity'] ?? $row->severity,
            'advisory' => $validated['advisory'] ?? $row->advisory,
            'item_distributed' => $validated['item_distributed'] ?? $row->item_distributed,
            'quantity' => $validated['quantity'] ?? $row->quantity,
            'is_outbreak' => array_key_exists('is_outbreak', $validated)
                ? (bool) $validated['is_outbreak']
                : $row->is_outbreak,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Field validation saved.',
            'data' => $row->fresh()->load('farmer', 'farmPlot'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = PestMonitoring::with('farmer')->findOrFail($id);
        $isPending = ! $row->latitude || ! $row->photo_path;
        $denied = $this->assertCanArchive($request, $row->farmer, $isPending);
        if ($denied) {
            return $denied;
        }

        $remarks = $this->remarksForArchive(
            $request,
            $isPending,
            'A justification is required before voiding a validated pest surveillance record.',
        );

        $snapshot = $row->only([
            'crop',
            'crop_stage',
            'variety',
            'area_planted',
            'days_after_planting',
            'area_damage_pct',
            'pest_name',
            'incidence',
            'severity',
            'date_of_inspection',
            'photo_path',
            'latitude',
            'longitude',
            'item_distributed',
            'quantity',
        ]);

        $row->delete();
        $this->logReportAudit('pest_monitoring.deleted', $row, [
            'before' => $snapshot,
            'after' => ['deleted_at' => optional($row->deleted_at)->toIso8601String() ?? now()->toIso8601String()],
            'remarks' => $remarks,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pest inspection removed.',
        ]);
    }
}
