<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Support\CalamityTypes;
use App\Models\FarmPlot;
use App\Models\Farmer;
use App\Models\GeoTag;
use App\Models\GeoTagRefusal;
use App\Models\HarvestLog;
use App\Models\PestMonitoring;
use App\Models\PlantingLog;
use App\Models\Program;
use App\Models\StandingCropLog;
use App\Models\SubsidyProgram;
use App\Services\FarmAreaBudgetService;
use App\Services\PolygonIntegrityService;
use App\Services\SystemAuditLogger;
use App\Traits\AssertsPlotAreaCap;
use App\Traits\DecodesBase64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    use DecodesBase64Image;
    use AssertsPlotAreaCap;

    public function __construct(
        private DistributionController $distributions,
        private SubsidyController $subsidies,
        private PolygonIntegrityService $polygonIntegrity,
        private FarmAreaBudgetService $farmAreaBudget,
    ) {
    }

    /**
     * Alias matching the offline sync engine naming.
     */
    public function bulkSync(Request $request): JsonResponse
    {
        return $this->bulkUpload($request);
    }

    /**
     * Bulk upload of records queued offline on a technician's device.
     *
     * Expected payload (all keys optional):
     * {
     *   "device_id": "...",
     *   "distributions": [...],
     *   "assessments": [...],
     *   "planting_logs": [...],
     *   "pest_reports": [...],
     *   "farm_profiles": [...],
     *   "geo_tags": [...],
     *   "geo_tag_refusals": [...],
     *   "harvest_logs": [...],
     *   "standing_crop_logs": [...]
     * }
     */
    public function bulkUpload(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id');
        $technicianId = $request->user()->id;

        $results = [
            'distributions' => [],
            'assessments' => [],
            'planting_logs' => [],
            'pest_reports' => [],
            'farm_profiles' => [],
            'field_distributions' => [],
            'geo_tags' => [],
            'geo_tag_refusals' => [],
            'harvest_logs' => [],
            'standing_crop_logs' => [],
        ];

        foreach ((array) $request->input('distributions', []) as $item) {
            try {
                $results['distributions'][] = $this->syncDistribution($item, $technicianId, $deviceId);
            } catch (\Throwable $e) {
                Log::error('Distribution sync failed: '.$e->getMessage());
                $clientId = $item['client_id'] ?? ($item['id'] ?? null);
                $results['distributions'][] = $this->itemResult(
                    is_string($clientId) ? $clientId : null,
                    'failed',
                    $e->getMessage() !== '' ? $e->getMessage() : 'Server error while saving distribution.',
                );
            }
        }

        foreach ((array) $request->input('assessments', []) as $item) {
            try {
                $results['assessments'][] = $this->syncAssessment($item, $technicianId, $deviceId);
            } catch (\Throwable $e) {
                Log::error('Assessment sync failed: '.$e->getMessage());
                $clientId = $item['client_id'] ?? ($item['id'] ?? null);
                $results['assessments'][] = $this->itemResult(
                    is_string($clientId) ? $clientId : null,
                    'failed',
                    'Server error while saving assessment.',
                );
            }
        }

        $hasOfflineBatch = $request->has('planting_logs')
            || $request->has('pest_reports')
            || $request->has('farm_profiles')
            || $request->has('field_distributions')
            || $request->has('geo_tags')
            || $request->has('geo_tag_refusals')
            || $request->has('harvest_logs')
            || $request->has('standing_crop_logs');

        if ($hasOfflineBatch) {
            try {
                // Per-item validation failures must not roll back siblings or
                // return HTTP 500 — the client needs `results` on 200 to mark
                // failed rows and drop synced ones. Unexpected crashes still 500.
                // Geo-tags first so newly created farm_plots exist before crop logs.
                if ($request->has('geo_tags')) {
                    foreach ((array) $request->input('geo_tags', []) as $item) {
                        $results['geo_tags'][] = $this->syncGeoTag($item, $technicianId, $deviceId);
                    }
                }

                if ($request->has('geo_tag_refusals')) {
                    foreach ((array) $request->input('geo_tag_refusals', []) as $item) {
                        $results['geo_tag_refusals'][] = $this->syncGeoTagRefusal($item, $technicianId, $deviceId);
                    }
                }

                if ($request->has('planting_logs')) {
                    foreach ((array) $request->input('planting_logs', []) as $item) {
                        $results['planting_logs'][] = $this->syncPlantingLog($item, $technicianId, $deviceId);
                    }
                }

                if ($request->has('pest_reports')) {
                    foreach ((array) $request->input('pest_reports', []) as $item) {
                        $results['pest_reports'][] = $this->syncPestReport($item, $technicianId, $deviceId);
                    }
                }

                if ($request->has('farm_profiles')) {
                    foreach ((array) $request->input('farm_profiles', []) as $item) {
                        $results['farm_profiles'][] = $this->syncFarmProfile($item, $deviceId);
                    }
                }

                if ($request->has('field_distributions')) {
                    foreach ((array) $request->input('field_distributions', []) as $item) {
                        $results['field_distributions'][] = $this->syncFieldDistribution($item, $technicianId, $deviceId);
                    }
                }

                if ($request->has('harvest_logs')) {
                    foreach ((array) $request->input('harvest_logs', []) as $item) {
                        $results['harvest_logs'][] = $this->syncHarvestLog($item, $technicianId, $deviceId);
                    }
                }

                if ($request->has('standing_crop_logs')) {
                    foreach ((array) $request->input('standing_crop_logs', []) as $item) {
                        $results['standing_crop_logs'][] = $this->syncStandingCropLog($item, $technicianId, $deviceId);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Offline bulk sync failed: '.$e->getMessage());

                return response()->json([
                    'status' => 'error',
                    'message' => 'Sync failed. '.$e->getMessage(),
                    'results' => $results,
                ], 500);
            }
        }

        $summary = [];
        foreach ($results as $key => $items) {
            $synced = 0;
            $failed = 0;
            $duplicate = 0;
            foreach ($items as $item) {
                $outcome = $item['outcome'] ?? '';
                if ($outcome === 'synced') {
                    $synced++;
                } elseif ($outcome === 'failed') {
                    $failed++;
                } elseif ($outcome === 'duplicate') {
                    $duplicate++;
                }
            }
            if ($synced + $failed + $duplicate > 0) {
                $summary[$key] = compact('synced', 'failed', 'duplicate');
            }
        }

        if ($summary !== []) {
            app(SystemAuditLogger::class)->record('sync.bulk.completed', $request->user(), null, [
                'device_id' => $deviceId,
                'summary' => $summary,
            ], $request);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Sync Successful',
            'results' => $results,
        ], 200);
    }

    private function syncDistribution(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);

        $farmerId = $this->resolveFarmerId(
            $item['farmer_id'] ?? null,
            $item['rsbsa_no'] ?? null,
            $item['farmer_name'] ?? null,
        );
        if ($farmerId) {
            $item['farmer_id'] = $farmerId;
        } else {
            unset($item['farmer_id']);
        }

        $source = $item['source'] ?? 'program';
        $programId = is_string($item['program_id'] ?? null) ? $item['program_id'] : null;
        if ($source !== 'subsidy' && $programId) {
            if (! Program::whereKey($programId)->exists() && SubsidyProgram::whereKey($programId)->exists()) {
                $source = 'subsidy';
            }
        }

        if ($source === 'subsidy') {
            return $this->syncSubsidyClaim($item, $clientId, $technicianId);
        }

        if (! $farmerId) {
            return $this->itemResult($clientId, 'failed', 'Could not match this farmer from the queued id/RSBSA.');
        }

        $validator = Validator::make($item, [
            'farmer_id' => 'required|uuid|exists:farmers,id',
            'program_id' => 'required|uuid|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $payload = [
            'client_id' => $clientId,
            'farmer_id' => $item['farmer_id'],
            'program_id' => $item['program_id'],
            'device_id' => $item['device_id'] ?? $deviceId,
            'claimed_at' => $item['claimed_at'] ?? null,
            'geo_tag_lat' => $item['geo_tag_lat'] ?? null,
            'geo_tag_long' => $item['geo_tag_long'] ?? null,
            'photo_proof_base64' => $item['photo_proof_base64'] ?? null,
        ];

        $result = $this->distributions->executeClaim($payload, $technicianId);

        return $this->itemResult($clientId, $result['outcome'] ?? 'failed', $result['body']['message'] ?? 'Claim could not be saved.');
    }

    /** Re-verifies eligibility/stock via SubsidyController::executeClaim() before accepting an offline claim. */
    private function syncSubsidyClaim(array $item, ?string $clientId, string $technicianId): array
    {
        $validator = Validator::make($item, [
            'program_id' => 'required|uuid|exists:tbl_subsidy_programs,id',
            'farmer_id' => 'nullable|uuid|exists:farmers,id',
            'rsbsa_no' => 'nullable|string|max:64',
            'beneficiary_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        try {
            $result = $this->subsidies->executeClaim($item['program_id'], $item, $technicianId);

            return $this->itemResult($clientId, $result['outcome'] ?? 'failed', $result['message'] ?? 'Subsidy claim could not be saved.');
        } catch (\Throwable $e) {
            Log::error('Offline subsidy claim sync failed: '.$e->getMessage());

            return $this->itemResult(
                $clientId,
                'failed',
                $e->getMessage() !== '' ? $e->getMessage() : 'Server error while releasing subsidy.',
            );
        }
    }

    private function syncAssessment(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['id'] ?? ($item['client_id'] ?? null);
        $assessmentId = $item['assessment_id'] ?? null;

        // Field-validating an assessment that already exists on the server
        // (dispatched from the barangay queue): update it instead of inserting.
        if ($assessmentId) {
            return $this->syncAssessmentUpdate($assessmentId, $clientId, $item, $technicianId);
        }

        $validator = Validator::make($item, [
            'farm_plot_id' => 'required|uuid|exists:farm_plots,id',
            'calamity_type' => ['required', CalamityTypes::rule()],
            'calamity_name' => 'nullable|string|max:255',
            'crop_stage' => ['nullable', Rule::in(['Seedling', 'Vegetative', 'Reproductive', 'Maturity', 'Harvested'])],
            'variety' => 'nullable|string|max:128',
            'area_destroyed_ha' => 'nullable|numeric|min:0',
            'date_of_calamity' => 'required|date',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'photo_base64' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $plot = FarmPlot::find($item['farm_plot_id']);
        $cap = $plot ? (float) $plot->size_ha : null;
        if ($cap !== null && isset($item['area_destroyed_ha']) && (float) $item['area_destroyed_ha'] > $cap + 0.0001) {
            return $this->itemResult($clientId, 'failed', 'Area damaged cannot exceed the farmer farm size ('.$cap.' ha).');
        }

        if ($clientId && DamageAssessment::whereKey($clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Assessment already synced.');
        }

        try {
            $path = $this->storeBase64Image($item['photo_base64'], 'assessments');
            if ($path === null) {
                return $this->itemResult($clientId, 'failed', 'Photo evidence could not be decoded.');
            }

            $farmerId = $item['farmer_id'] ?? FarmPlot::whereKey($item['farm_plot_id'])->value('farmer_id');

            $assessment = DamageAssessment::create([
                'id' => $clientId,
                'farm_plot_id' => $item['farm_plot_id'],
                'farmer_id' => $farmerId,
                'technician_id' => $technicianId,
                'calamity_type' => $item['calamity_type'],
                'calamity_name' => $item['calamity_name'] ?? $item['calamity_type'],
                'crop_stage' => $item['crop_stage'] ?? null,
                'variety' => $item['variety'] ?? null,
                'area_destroyed_ha' => $this->resolveSyncedDestroyedArea($plot, $item),
                'date_of_calamity' => $item['date_of_calamity'],
                'damage_percentage' => $item['damage_percentage'],
                'estimated_value_lost' => $item['estimated_value_lost'] ?? null,
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'device_id' => $item['device_id'] ?? $deviceId,
                'photo_evidence_path' => $path,
                'status' => 'Pending',
            ]);

            return $this->itemResult($clientId ?? $assessment->id, 'synced', 'Assessment filed.');
        } catch (\Exception $e) {
            Log::error('Assessment sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving assessment.');
        }
    }

    /** Mirrors DamageAssessmentController::fieldValidate() for the offline queue. */
    private function syncAssessmentUpdate(string $assessmentId, ?string $clientId, array $item, string $technicianId): array
    {
        $assessment = DamageAssessment::find($assessmentId);
        if (! $assessment) {
            return $this->itemResult($clientId, 'failed', 'Dispatched assessment no longer exists on the server.');
        }

        // Queue membership is photo + GPS, not status. Barangay verify() can
        // flip status to Verified without field evidence — still attach proof.
        if ($assessment->photo_evidence_path && $assessment->latitude) {
            return $this->itemResult($clientId ?? $assessmentId, 'duplicate', 'Assessment already field-validated.');
        }

        if (empty($item['latitude']) || empty($item['longitude']) || empty($item['photo_base64'])) {
            return $this->itemResult($clientId ?? $assessmentId, 'failed', 'GPS coordinates and a photo are required to field-validate.');
        }

        $pct = isset($item['damage_percentage']) ? (float) $item['damage_percentage'] : (float) $assessment->damage_percentage;
        $areaPlanted = (float) ($assessment->area_planted_ha ?? 0);
        $destroyedHa = isset($item['area_destroyed_ha']) && (float) $item['area_destroyed_ha'] > 0
            ? (float) $item['area_destroyed_ha']
            : $this->resolveSyncedDestroyedArea(
                $assessment->farm_plot_id ? FarmPlot::find($assessment->farm_plot_id) : null,
                ['area_destroyed_ha' => null, 'damage_percentage' => $pct, 'area_planted_ha' => $areaPlanted],
            );

        $plot = $assessment->farm_plot_id ? FarmPlot::find($assessment->farm_plot_id) : null;
        $cap = $plot ? (float) $plot->size_ha : null;
        if ($cap !== null && $areaPlanted > 0) {
            $cap = min($cap, $areaPlanted);
        }
        if ($cap !== null && $destroyedHa > $cap + 0.0001) {
            return $this->itemResult($clientId ?? $assessmentId, 'failed', 'Area damaged cannot exceed the farm plot size ('.$cap.' ha).');
        }

        try {
            $path = $this->storeBase64Image($item['photo_base64'], 'assessments');
            if ($path === null) {
                return $this->itemResult($clientId ?? $assessmentId, 'failed', 'Photo evidence could not be decoded.');
            }

            $assessment->update([
                'latitude' => $item['latitude'],
                'longitude' => $item['longitude'],
                'photo_evidence_path' => $path,
                'technician_id' => $technicianId,
                'area_destroyed_ha' => $destroyedHa,
                'damage_percentage' => $pct,
                'variety' => $item['variety'] ?? $assessment->variety,
                'crop_stage' => $item['crop_stage'] ?? $assessment->crop_stage,
                'estimated_value_lost' => $item['estimated_value_lost'] ?? $assessment->estimated_value_lost,
                'status' => 'Verified',
                'verified_by' => $technicianId,
                'verified_at' => now(),
            ]);

            return $this->itemResult($clientId ?? $assessmentId, 'synced', 'Assessment field-validated.');
        } catch (\Exception $e) {
            Log::error('Assessment field-validate sync failed: '.$e->getMessage());

            return $this->itemResult($clientId ?? $assessmentId, 'failed', 'Server error while updating assessment.');
        }
    }

    private function syncPlantingLog(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);

        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);
        if (! $farmerId) {
            return $this->itemResult($clientId, 'failed', 'Could not match this farmer from the queued id/RSBSA.');
        }

        $validator = Validator::make([
            ...$item,
            'farmer_id' => $farmerId,
        ], [
            'farmer_id' => 'required|uuid|exists:farmers,id',
            'crop_type' => 'required|string|max:64',
            'variety' => 'required|string|max:128',
            'area_planted' => 'required|numeric|min:0',
            'date_planted' => 'required|date',
            'status' => 'nullable|string|max:64',
            'water_source' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $plotId = $this->usablePlotId($item['farm_plot_id'] ?? null);

        $areaError = $this->plotAreaExceedsCap($plotId, (float) $item['area_planted']);
        if ($areaError) {
            return $this->itemResult($clientId, 'failed', $areaError);
        }

        if ($clientId && PlantingLog::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Planting log already synced.');
        }

        try {
            $log = PlantingLog::create([
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
                'farm_plot_id' => $plotId,
                'technician_id' => $technicianId,
                'crop_type' => $item['crop_type'],
                'variety' => $item['variety'],
                'area_planted' => $item['area_planted'],
                'date_planted' => $item['date_planted'],
                'status' => $item['status'] ?? 'Active',
                'water_source' => $item['water_source'] ?? null,
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'device_id' => $item['device_id'] ?? $deviceId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Planting log sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving planting log.');
        }

        return $this->itemResult($clientId ?? $log->id, 'synced', 'Planting log saved.');
    }

    private function syncHarvestLog(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);
        if (! $farmerId) {
            return $this->itemResult($clientId, 'failed', 'Could not match this farmer from the queued id/RSBSA.');
        }

        $validator = Validator::make([
            ...$item,
            'farmer_id' => $farmerId,
        ], [
            'farmer_id' => 'required|uuid|exists:farmers,id',
            'crop_type' => 'required|string|max:64',
            'variety' => 'required|string|max:128',
            'area_harvested' => 'required|numeric|min:0',
            'total_yield' => 'required|numeric|min:0',
            'date_harvested' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $plotId = $this->usablePlotId($item['farm_plot_id'] ?? null);

        $areaError = $this->plotAreaExceedsCap($plotId, (float) $item['area_harvested']);
        if ($areaError) {
            return $this->itemResult($clientId, 'failed', $areaError);
        }

        if ($clientId && HarvestLog::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Harvest log already synced.');
        }

        $farmer = Farmer::find($farmerId);

        try {
            $log = HarvestLog::create([
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
                'farm_plot_id' => $plotId,
                'technician_id' => $technicianId,
                'crop_type' => $item['crop_type'],
                'variety' => $item['variety'],
                'area_harvested' => $item['area_harvested'],
                'total_yield' => $item['total_yield'],
                'yield_unit' => 'Metric Tons',
                'date_harvested' => $item['date_harvested'],
                'farm_location' => $item['farm_location'] ?? $farmer?->permanent_brgy,
            ]);
        } catch (\Throwable $e) {
            Log::error('Harvest log sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving harvest log.');
        }

        return $this->itemResult($clientId ?? $log->id, 'synced', 'Harvest log saved.');
    }

    private function syncStandingCropLog(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);
        if (! $farmerId) {
            return $this->itemResult($clientId, 'failed', 'Could not match this farmer from the queued id/RSBSA.');
        }

        $validator = Validator::make([
            ...$item,
            'farmer_id' => $farmerId,
        ], [
            'farmer_id' => 'required|uuid|exists:farmers,id',
            'crop_type' => 'required|string|max:64',
            'variety' => 'required|string|max:128',
            'area_ha' => 'required|numeric|min:0',
            'est_harvest_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $plotId = $this->usablePlotId($item['farm_plot_id'] ?? null);

        $areaError = $this->plotAreaExceedsCap($plotId, (float) $item['area_ha']);
        if ($areaError) {
            return $this->itemResult($clientId, 'failed', $areaError);
        }

        if ($clientId && StandingCropLog::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Standing crop log already synced.');
        }

        $farmer = Farmer::find($farmerId);

        try {
            $log = StandingCropLog::create([
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
                'farm_plot_id' => $plotId,
                'technician_id' => $technicianId,
                'crop_type' => $item['crop_type'],
                'variety' => $item['variety'],
                'area_ha' => $item['area_ha'],
                'growth_stage' => $item['growth_stage'] ?? 'Vegetative',
                'est_harvest_date' => $item['est_harvest_date'],
                'farm_location' => $item['farm_location'] ?? $farmer?->permanent_brgy,
            ]);
        } catch (\Throwable $e) {
            Log::error('Standing crop log sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving standing crop log.');
        }

        return $this->itemResult($clientId ?? $log->id, 'synced', 'Standing crop log saved.');
    }

    private function syncPestReport(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);
        $serverId = $item['server_id'] ?? null;

        $validator = Validator::make($item, [
            'crop' => 'nullable|string|max:64',
            'incidence' => 'required|numeric|min:0|max:100',
            'severity' => 'required|string|max:32',
            'advisory' => 'nullable|string',
            'is_outbreak' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $lat = $item['lat'] ?? ($item['latitude'] ?? null);
        $lng = $item['lng'] ?? ($item['longitude'] ?? null);

        if ($serverId) {
            $existing = PestMonitoring::find($serverId);
            if (! $existing) {
                return $this->itemResult($clientId, 'failed', 'Dispatched pest report no longer exists on the server.');
            }

            if ($lat === null || $lng === null || empty($item['photo_base64'])) {
                return $this->itemResult($clientId, 'failed', 'GPS coordinates and a photo are required to field-validate.');
            }

            $photoPath = $this->storeBase64Image($item['photo_base64'], 'pest-monitoring');
            if ($photoPath === null) {
                return $this->itemResult($clientId, 'failed', 'Pest photo could not be decoded.');
            }

            try {
                $existing->update([
                    'technician_id' => $technicianId,
                    'pest_name' => $item['pest_name'] ?? $existing->pest_name,
                    'incidence' => (int) $item['incidence'],
                    'severity' => $item['severity'],
                    'advisory' => $item['advisory'] ?? $existing->advisory,
                    'is_outbreak' => array_key_exists('is_outbreak', $item)
                        ? (bool) $item['is_outbreak']
                        : $existing->is_outbreak,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'photo_path' => $photoPath,
                    'report_ref' => $item['report_id'] ?? $existing->report_ref,
                    'item_distributed' => $item['item_distributed'] ?? $existing->item_distributed,
                    'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : $existing->quantity,
                    'device_id' => $item['device_id'] ?? $deviceId,
                ]);
            } catch (\Throwable $e) {
                Log::error('Pest report update failed: '.$e->getMessage());

                return $this->itemResult($clientId, 'failed', 'Server error while saving pest report.');
            }

            return $this->itemResult($clientId ?? $existing->id, 'synced', 'Pest report updated.');
        }

        if ($lat === null || $lng === null || empty($item['photo_base64'])) {
            return $this->itemResult($clientId, 'failed', 'GPS coordinates and a photo are required.');
        }

        $photoPath = $this->storeBase64Image($item['photo_base64'], 'pest-monitoring');
        if ($photoPath === null) {
            return $this->itemResult($clientId, 'failed', 'Pest photo could not be decoded.');
        }

        if ($clientId && PestMonitoring::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Pest report already synced.');
        }

        try {
            $row = PestMonitoring::create([
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
                'technician_id' => $technicianId,
                'crop' => $item['crop'] ?? null,
                'pest_name' => $item['pest_name'] ?? null,
                'incidence' => (int) $item['incidence'],
                'severity' => $item['severity'],
                'advisory' => $item['advisory'] ?? null,
                'is_outbreak' => (bool) ($item['is_outbreak'] ?? false),
                'latitude' => $lat,
                'longitude' => $lng,
                'photo_path' => $photoPath,
                'report_ref' => $item['report_id'] ?? null,
                'item_distributed' => $item['item_distributed'] ?? null,
                'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : null,
                'device_id' => $item['device_id'] ?? $deviceId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Pest report sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving pest report.');
        }

        return $this->itemResult($clientId ?? $row->id, 'synced', 'Pest report saved.');
    }

    private function syncFieldDistribution(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $rsbsa = $item['rsbsa_id'] ?? null;
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $rsbsa);

        $validator = Validator::make($item, [
            'rsbsa_id' => 'required|string|max:64',
            'item_dispensed' => 'required|string|max:255',
            'quantity' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        if ($clientId && DB::table('field_distribution_logs')->where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Field distribution already synced.');
        }

        try {
            DB::table('field_distribution_logs')->insert([
                'id' => (string) Str::uuid(),
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
                'technician_id' => $technicianId,
                'rsbsa_id' => $rsbsa,
                'item_dispensed' => $item['item_dispensed'],
                'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : null,
                'dispensed_at' => $item['timestamp'] ?? now(),
                'program_id' => $item['program_id'] ?? null,
                'device_id' => $item['device_id'] ?? $deviceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Field distribution sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving field distribution.');
        }

        return $this->itemResult($clientId, 'synced', 'Field distribution saved.');
    }

    private function syncFarmProfile(array $item, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);

        if (! $farmerId) {
            return $this->itemResult($clientId, 'failed', 'farmer_id is required to update farm plots.');
        }

        $coords = $this->parseCoordinates($item['coordinates'] ?? null);
        if ($coords === null) {
            return $this->itemResult($clientId, 'failed', 'Invalid farm coordinates payload.');
        }

        $plot = FarmPlot::where('farmer_id', $farmerId)->orderBy('created_at')->first();
        if (! $plot) {
            return $this->itemResult($clientId, 'failed', 'No farm plot found for this farmer.');
        }

        $lat = $coords['lat'];
        $lng = $coords['lng'];
        $totalArea = isset($item['total_area']) ? (float) $item['total_area'] : null;

        $plot->latitude = $lat;
        $plot->longitude = $lng;
        if ($totalArea !== null && $totalArea > 0) {
            $plot->size_ha = $totalArea;
            $plot->total_parcel_area_ha = $totalArea;
        }

        try {
            $plot->save();

            DB::update(
                'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
                [$lng, $lat, $plot->id]
            );
        } catch (\Throwable $e) {
            Log::error('Farm profile sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving farm profile.');
        }

        return $this->itemResult($clientId, 'synced', 'Farm plot profile updated.');
    }

    /**
     * DA-RSBSA Georeferencing (RCM Protocol) sync: persists a full geo-tag
     * audit trail, and when the capture is a farm boundary polygon, creates
     * the corresponding `farm_plots` record (gross area minus the declared
     * non-productive/infrastructure deduction) and fires the Semaphore SMS
     * georeferencing receipt.
     */
    private function syncGeoTag(array $item, ?string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);

        if ($clientId && GeoTag::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Geo-tag already synced.');
        }

        $incidentType = $item['incident_type'] ?? 'none';
        if (! is_string($incidentType) || trim($incidentType) === '') {
            $incidentType = 'none';
        }
        $item['incident_type'] = $incidentType;

        $validator = Validator::make($item, [
            'geometry_type' => ['required', Rule::in(['polygon', 'marker'])],
            'coordinates' => 'required',
            'crop_planted' => 'nullable|string|max:100',
            'incident_type' => ['nullable', Rule::in(['none', 'pest', 'calamity'])],
            'non_productive_area_sqm' => 'nullable|numeric|min:0',
            'farm_plot_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $geometryType = $item['geometry_type'];
        $points = $this->parseGeoTagPoints($item['coordinates'] ?? null, $geometryType);
        if ($points === null && $geometryType === 'marker') {
            $points = $this->parseGeoTagPoints($item['coordinates'] ?? null, 'polygon');
        }

        if ($points === null || $points === []) {
            return $this->itemResult($clientId, 'failed', 'Invalid geo-tag coordinates payload.');
        }

        $nonProductiveSqm = (float) ($item['non_productive_area_sqm'] ?? 0);
        $grossAreaSqm = $geometryType === 'polygon' && count($points) >= 3
            ? $this->polygonAreaSqm($points)
            : ($geometryType === 'polygon' ? 0.0 : null);
        $finalAreaSqm = $grossAreaSqm !== null ? max(0.0, $grossAreaSqm - $nonProductiveSqm) : null;
        $finalAreaHa = $finalAreaSqm !== null ? round($finalAreaSqm / 10000, 4) : null;

        // Defense / GPS-same-spot walks: a polygon with no area is stored as a
        // location pin instead of rejected. Real perimeter walks are unchanged.
        if ($geometryType === 'polygon' && ($finalAreaHa === null || $finalAreaHa <= 0 || ($grossAreaSqm ?? 0) < 1.0)) {
            $geometryType = 'marker';
            $item['geometry_type'] = 'marker';
            $points = [$this->polygonCentroid($points)];
            $grossAreaSqm = null;
            $finalAreaSqm = null;
            $finalAreaHa = null;
        }

        // ── DA Polygon Integrity Checks (polygon boundaries only) ──────────────
        if ($geometryType === 'polygon') {
            // 1. Start/End Gap Rule — walk start and end must be ≤ 10 m apart.
            if ($this->polygonIntegrity->hasUnclosedGap($points)) {
                $first = $points[0];
                $last  = $points[count($points) - 1];
                $gapM  = round($this->polygonIntegrity->haversineMeters(
                    $first['lat'], $first['lng'], $last['lat'], $last['lng'],
                ));

                return $this->itemResult(
                    $clientId,
                    'failed',
                    "Validation Failed: Start–End gap is {$gapM} m. DA guidelines require ≤ "
                    . PolygonIntegrityService::GAP_LIMIT_METERS . ' m. Walk back to the starting stake.',
                );
            }

            // 2. Spatial Overlap Rule — new boundary must not intersect any existing plot.
            $excludePlotId = $item['farm_plot_id'] ?? null;
            $collision = $this->polygonIntegrity->findOverlappingPlot(
                $points,
                is_string($excludePlotId) ? $excludePlotId : null,
            );
            if ($collision !== null) {
                return $this->itemResult(
                    $clientId,
                    'failed',
                    'Validation Failed: Polygon overlaps with an existing farm boundary. Please adjust coordinates.',
                );
            }
        }

        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);
        if (! $farmerId && ! empty($item['farm_plot_id'])) {
            $farmerId = FarmPlot::whereKey($item['farm_plot_id'])->value('farmer_id');
        }

        $photoPath = null;
        $farmerSignaturePath = null;
        $aewSignaturePath = null;
        try {
            if (! empty($item['photo_base64'])) {
                $photoPath = $this->storeBase64Image($item['photo_base64'], 'geo-tags');
            }
            if (! empty($item['farmer_signature_base64'])) {
                $farmerSignaturePath = $this->storeBase64Image($item['farmer_signature_base64'], 'geo-tags/signatures');
            }
            if (! empty($item['aew_signature_base64'])) {
                $aewSignaturePath = $this->storeBase64Image($item['aew_signature_base64'], 'geo-tags/signatures');
            }
        } catch (\Throwable $e) {
            Log::error('Geo-tag media store failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving geo-tag photo.');
        }

        $hasDiscrepancy = (bool) ($item['has_discrepancy'] ?? false);

        // Polygon walks must update farm_plots (admin + dispatch queue read that
        // table). Fail before writing geo_tags so a retry can still succeed.
        if ($geometryType === 'polygon') {
            if (! $farmerId) {
                return $this->itemResult($clientId, 'failed', 'Farmer could not be resolved for this geo-tag walk.');
            }
        }

        try {
            $geoTag = DB::transaction(function () use (
                $geometryType,
                $farmerId,
                $points,
                $finalAreaHa,
                $nonProductiveSqm,
                $hasDiscrepancy,
                $item,
                $clientId,
                $technicianId,
                $deviceId,
                $photoPath,
                $farmerSignaturePath,
                $aewSignaturePath,
                $grossAreaSqm,
                $finalAreaSqm,
            ) {
                $farmPlot = null;
                if ($geometryType === 'polygon') {
                    $farmPlot = $this->createFarmPlotFromBoundary(
                        (string) $farmerId,
                        $points,
                        (float) $finalAreaHa,
                        $nonProductiveSqm,
                        $hasDiscrepancy,
                        $item,
                    );
                    if ($farmPlot->farmer_id) {
                        $farmerId = (string) $farmPlot->farmer_id;
                    }
                } elseif (! empty($item['farm_plot_id']) && isset($points[0])) {
                    $farmPlot = $this->stampFarmPlotFromPin(
                        (string) ($farmerId ?? ''),
                        $points[0],
                        $item,
                    );
                    if ($farmPlot?->farmer_id) {
                        $farmerId = (string) $farmPlot->farmer_id;
                    }
                }

                return GeoTag::create([
                    'client_id' => $clientId,
                    'farmer_id' => $farmerId,
                    'farm_plot_id' => $farmPlot?->id,
                    'rsbsa_no' => $item['rsbsa_no'] ?? null,
                    'technician_id' => $technicianId,
                    'device_id' => $item['device_id'] ?? $deviceId,
                    'geometry_type' => $geometryType,
                    'coordinates' => $points,
                    'crop_planted' => $item['crop_planted'] ?? null,
                    'crop_variety' => $item['crop_variety'] ?? null,
                    'planting_start_month' => $item['planting_start_month'] ?? null,
                    'planting_end_month' => $item['planting_end_month'] ?? null,
                    'incident_type' => $item['incident_type'] ?? 'none',
                    'observations' => $item['observations'] ?? null,
                    'photo_path' => $photoPath,
                    'farmer_signature_path' => $farmerSignaturePath,
                    'aew_signature_path' => $aewSignaturePath,
                    'accuracy_m' => $item['accuracy_m'] ?? null,
                    'gross_area_sqm' => $grossAreaSqm,
                    'non_productive_area_sqm' => $nonProductiveSqm,
                    'final_area_sqm' => $finalAreaSqm,
                    'final_area_ha' => $finalAreaHa,
                    'has_discrepancy' => $hasDiscrepancy,
                ]);
            });

            return $this->itemResult($clientId ?? $geoTag->id, 'synced', 'Geo-tag saved.');
        } catch (\InvalidArgumentException $e) {
            return $this->itemResult($clientId, 'failed', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Geo-tag sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while saving geo-tag.');
        }
    }

    /**
     * DA "3-Attempt Rule": persists a farmer's refusal to consent to
     * georeferencing. Three logged attempts flag the record for the RSBSA
     * exclusion protocol during MAO review.
     */
    private function syncGeoTagRefusal(array $item, ?string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);

        if ($clientId && GeoTagRefusal::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Refusal already logged.');
        }

        $validator = Validator::make($item, [
            'attempt_number' => 'required|integer|min:1|max:3',
            'reason' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null, $item['farmer_name'] ?? null);

        try {
            $refusal = GeoTagRefusal::create([
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
                'rsbsa_no' => $item['rsbsa_no'] ?? null,
                'technician_id' => $technicianId,
                'device_id' => $item['device_id'] ?? $deviceId,
                'attempt_number' => (int) $item['attempt_number'],
                'reason' => $item['reason'],
            ]);

            return $this->itemResult($clientId ?? $refusal->id, 'synced', 'Refusal logged.');
        } catch (\Throwable $e) {
            Log::error('Geo-tag refusal sync failed: '.$e->getMessage());

            return $this->itemResult($clientId, 'failed', 'Server error while logging refusal.');
        }
    }

    /**
     * Creates the RSBSA farm boundary plot from a completed polygon walk.
     * `size_ha`/`total_parcel_area_ha` reflect the *final verified area*
     * (gross area minus the non-productive/infrastructure deduction).
     */
    private function createFarmPlotFromBoundary(
        string $farmerId,
        array $points,
        float $areaHa,
        float $nonProductiveSqm,
        bool $hasDiscrepancy,
        array $item,
    ): FarmPlot {
        $centroid = $this->polygonCentroid($points);
        $farmer = Farmer::find($farmerId);
        $commodity = $item['crop_planted'] ?? 'Rice';

        $existing = null;
        $requestedPlotId = $item['farm_plot_id'] ?? null;
        if (is_string($requestedPlotId) && $requestedPlotId !== '') {
            $existing = FarmPlot::query()->where('id', $requestedPlotId)->first();
            if (! $existing) {
                throw new \InvalidArgumentException('Dispatched farm plot was not found.');
            }
            // Dispatched ticket is source of truth — use the plot's farmer
            // even if the payload farmer_id is missing or mismatched.
            if ($existing->farmer_id) {
                $farmerId = (string) $existing->farmer_id;
                $farmer = Farmer::find($farmerId) ?: $farmer;
            }
        }
        if (! $existing) {
            $existing = FarmPlot::query()
                ->where('farmer_id', $farmerId)
                ->whereRaw('LOWER(commodity) = ?', [strtolower((string) $commodity)])
                ->orderBy('created_at')
                ->first();
        }
        if (! $existing) {
            $existing = FarmPlot::query()
                ->where('farmer_id', $farmerId)
                ->orderBy('created_at')
                ->first();
        }

        if (! $farmer) {
            throw new \InvalidArgumentException('Farmer not found for geo-tag boundary.');
        }

        $excludePlotId = $existing?->id;
        $budgetError = $this->farmAreaBudget->assertWithinBudget(
            $farmer,
            $areaHa,
            $excludePlotId,
            $hasDiscrepancy,
        );
        if ($budgetError) {
            $payload = $budgetError->getData(true);
            throw new \InvalidArgumentException(
                $payload['message'] ?? 'Mapped area exceeds the registered farm area quota.'
            );
        }

        if ($existing) {
            $existing->update([
                'location_brgy' => $item['location_brgy'] ?? $existing->location_brgy ?? ($farmer->permanent_brgy ?? 'Unspecified'),
                'latitude' => $centroid['lat'],
                'longitude' => $centroid['lng'],
                'total_parcel_area_ha' => $areaHa,
                'size_ha' => $areaHa,
                'commodity' => $commodity,
                'boundary_points' => $points,
                'non_productive_area_sqm' => $nonProductiveSqm,
                'has_discrepancy' => $hasDiscrepancy,
                'parcel_name' => $item['parcel_name'] ?? $existing->parcel_name,
                'planting_start_month' => $item['planting_start_month'] ?? $existing->planting_start_month,
                'planting_end_month' => $item['planting_end_month'] ?? $existing->planting_end_month,
                'remarks' => $item['observations'] ?? $existing->remarks,
                'geotag_status' => 'mapped',
                'geotag_assigned_user_id' => null,
                'geotag_assigned_name' => null,
                'geotag_priority' => null,
                'geotag_notes' => null,
                'geotag_deadline' => null,
            ]);

            DB::update(
                'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
                [$centroid['lng'], $centroid['lat'], $existing->id]
            );

            return FarmPlot::with('farmer')->findOrFail($existing->id);
        }

        $id = (string) Str::uuid();

        DB::table('farm_plots')->insert([
            'id' => $id,
            'farmer_id' => $farmerId,
            'location_brgy' => $item['location_brgy'] ?? ($farmer->permanent_brgy ?? 'Unspecified'),
            'location_city' => $item['location_city'] ?? 'Echague',
            'location_province' => $item['location_province'] ?? 'Isabela',
            'latitude' => $centroid['lat'],
            'longitude' => $centroid['lng'],
            'total_parcel_area_ha' => $areaHa,
            'is_ancestral_domain' => false,
            'is_agrarian_reform_beneficiary' => false,
            'ownership_type' => 'Registered Owner',
            'proof_of_ownership_document' => 'Mobile GIS Boundary Walk',
            'commodity' => $item['crop_planted'] ?? 'Rice',
            'size_ha' => $areaHa,
            'farm_type' => 'Irrigated',
            'is_organic' => false,
            'boundary_points' => json_encode($points),
            'non_productive_area_sqm' => $nonProductiveSqm,
            'has_discrepancy' => $hasDiscrepancy,
            'parcel_name' => $item['parcel_name'] ?? null,
            'planting_start_month' => $item['planting_start_month'] ?? null,
            'planting_end_month' => $item['planting_end_month'] ?? null,
            'remarks' => $item['observations'] ?? null,
            'geotag_status' => 'mapped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::update(
            'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
            [$centroid['lng'], $centroid['lat'], $id]
        );

        return FarmPlot::with('farmer')->findOrFail($id);
    }

    /**
     * Location-pin fallback: stamp a dispatched plot's GPS without writing a
     * farm-boundary polygon or changing registered size_ha.
     *
     * @param  array{lat: float, lng: float}  $point
     */
    private function stampFarmPlotFromPin(string $farmerId, array $point, array $item): FarmPlot
    {
        $requestedPlotId = $item['farm_plot_id'] ?? null;
        if (! is_string($requestedPlotId) || $requestedPlotId === '') {
            throw new \InvalidArgumentException('Dispatched farm plot was not found.');
        }

        $plot = FarmPlot::query()->where('id', $requestedPlotId)->first();
        if (! $plot) {
            throw new \InvalidArgumentException('Dispatched farm plot was not found.');
        }

        $lat = (float) $point['lat'];
        $lng = (float) $point['lng'];

        $plot->update([
            'latitude' => $lat,
            'longitude' => $lng,
            'parcel_name' => $item['parcel_name'] ?? $plot->parcel_name,
            'planting_start_month' => $item['planting_start_month'] ?? $plot->planting_start_month,
            'planting_end_month' => $item['planting_end_month'] ?? $plot->planting_end_month,
            'remarks' => $item['observations'] ?? $plot->remarks,
            'geotag_status' => 'mapped',
            'geotag_assigned_user_id' => null,
            'geotag_assigned_name' => null,
            'geotag_priority' => null,
            'geotag_notes' => null,
            'geotag_deadline' => null,
        ]);

        DB::update(
            'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
            [$lng, $lat, $plot->id]
        );

        return FarmPlot::with('farmer')->findOrFail($plot->id);
    }

    /**
     * Parses geo-tag coordinates: a single {lat,lng} for markers, or an
     * ordered vertex list [{lat,lng}, ...] for boundary polygons.
     *
     * @return array<int, array{lat: float, lng: float}>|null
     */
    private function parseGeoTagPoints(mixed $raw, string $geometryType): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            }
        }

        if (! is_array($raw)) {
            return null;
        }

        if ($geometryType === 'marker') {
            if (isset($raw['lat'], $raw['lng'])) {
                return [['lat' => (float) $raw['lat'], 'lng' => (float) $raw['lng']]];
            }

            $listed = [];
            foreach ($raw as $p) {
                if (is_array($p) && isset($p['lat'], $p['lng'])) {
                    $listed[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
                }
            }
            if (! $listed) {
                return null;
            }

            return count($listed) === 1 ? $listed : [$this->polygonCentroid($listed)];
        }

        $points = [];
        foreach ($raw as $p) {
            if (is_array($p) && isset($p['lat'], $p['lng'])) {
                $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
            }
        }

        return $points ?: null;
    }

    /**
     * Equirectangular-projection shoelace area (meters), mirroring the
     * client-side estimate so the technician's preview matches the server's
     * authoritative figure.
     */
    private function polygonAreaSqm(array $points): float
    {
        $count = count($points);
        if ($count < 3) {
            return 0.0;
        }

        $earthRadius = 6371000;
        $meanLat = array_sum(array_column($points, 'lat')) / $count;
        $latRad0 = deg2rad($meanLat);
        $cosLat0 = cos($latRad0);

        $xy = array_map(function (array $p) use ($earthRadius, $cosLat0) {
            return [
                'x' => deg2rad($p['lng']) * $earthRadius * $cosLat0,
                'y' => deg2rad($p['lat']) * $earthRadius,
            ];
        }, $points);

        $area = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $area += $xy[$i]['x'] * $xy[$j]['y'] - $xy[$j]['x'] * $xy[$i]['y'];
        }

        return abs($area) / 2;
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private function polygonCentroid(array $points): array
    {
        $count = count($points);

        return [
            'lat' => array_sum(array_column($points, 'lat')) / $count,
            'lng' => array_sum(array_column($points, 'lng')) / $count,
        ];
    }

    /**
     * Accept UUID farmer_id, RSBSA number, or a unique "Surname, First" display name.
     */
    private function resolveFarmerId(mixed $farmerId, mixed $rsbsaNo = null, mixed $farmerName = null): ?string
    {
        $id = is_string($farmerId) ? trim($farmerId) : '';
        $rsbsa = is_string($rsbsaNo) ? trim($rsbsaNo) : '';

        if ($id !== '' && Str::isUuid($id) && Farmer::whereKey($id)->exists()) {
            return $id;
        }

        $lookup = $rsbsa !== '' ? $rsbsa : $id;
        if ($lookup !== '') {
            $fromRsbsa = Farmer::query()
                ->whereRaw('LOWER(rsbsa_no) = ?', [Str::lower($lookup)])
                ->value('id');
            if ($fromRsbsa) {
                return $fromRsbsa;
            }
        }

        $name = is_string($farmerName) ? trim(preg_replace('/\s+/', ' ', $farmerName) ?? '') : '';
        if ($name === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $name, 2));
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $surname = $parts[0];
        $firstToken = explode(' ', $parts[1])[0] ?? '';
        if ($firstToken === '') {
            return null;
        }

        $exact = Farmer::query()
            ->whereRaw('LOWER(surname) = ?', [Str::lower($surname)])
            ->whereRaw('LOWER(first_name) = ?', [Str::lower($firstToken)])
            ->limit(2)
            ->pluck('id');
        if ($exact->count() === 1) {
            return $exact->first();
        }

        if ($exact->count() === 0) {
            $prefix = Farmer::query()
                ->whereRaw('LOWER(surname) = ?', [Str::lower($surname)])
                ->whereRaw('LOWER(first_name) LIKE ?', [Str::lower($firstToken).'%'])
                ->limit(2)
                ->pluck('id');
            if ($prefix->count() === 1) {
                return $prefix->first();
            }
        }

        return null;
    }

    /** Keep a plot FK only when the plot still exists (not missing / not soft-deleted). */
    private function usablePlotId(mixed $farmPlotId): ?string
    {
        $plotId = $this->nullableUuid($farmPlotId);
        if (! $plotId) {
            return null;
        }

        return FarmPlot::whereKey($plotId)->exists() ? $plotId : null;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function parseCoordinates(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            }
        }

        if (! is_array($raw)) {
            return null;
        }

        // Single pin { lat, lng }
        if (isset($raw['lat'], $raw['lng'])) {
            return ['lat' => (float) $raw['lat'], 'lng' => (float) $raw['lng']];
        }

        // Polygon / walk points — use first vertex as pin
        if (isset($raw[0]['lat'], $raw[0]['lng'])) {
            return ['lat' => (float) $raw[0]['lat'], 'lng' => (float) $raw[0]['lng']];
        }

        return null;
    }

    private function resolveSyncedDestroyedArea(?FarmPlot $plot, array $item): ?float
    {
        if (isset($item['area_destroyed_ha']) && (float) $item['area_destroyed_ha'] > 0) {
            return (float) $item['area_destroyed_ha'];
        }

        $base = (float) ($item['area_planted_ha'] ?? $plot?->size_ha ?? 0);
        $pct = (float) ($item['damage_percentage'] ?? 0);
        if ($base <= 0) {
            return isset($item['area_destroyed_ha']) ? (float) $item['area_destroyed_ha'] : null;
        }

        return round($base * ($pct / 100), 4);
    }

    private function nullableUuid(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function itemResult(?string $clientId, string $outcome, string $message): array
    {
        return [
            'client_id' => $clientId,
            'outcome' => $outcome, // synced | duplicate | failed
            'message' => $message,
        ];
    }
}
