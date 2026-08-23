<?php

namespace App\Http\Controllers;

use App\Models\DamageAssessment;
use App\Models\FarmPlot;
use App\Models\Farmer;
use App\Models\GeoTag;
use App\Models\GeoTagRefusal;
use App\Models\PestMonitoring;
use App\Models\PlantingLog;
use App\Services\FarmAreaBudgetService;
use App\Services\PolygonIntegrityService;
use App\Services\SmsService;
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

    public function __construct(
        private DistributionController $distributions,
        private SmsService $sms,
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
     *   "geo_tag_refusals": [...]
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
        ];

        foreach ((array) $request->input('distributions', []) as $item) {
            $results['distributions'][] = $this->syncDistribution($item, $technicianId, $deviceId);
        }

        foreach ((array) $request->input('assessments', []) as $item) {
            $results['assessments'][] = $this->syncAssessment($item, $technicianId, $deviceId);
        }

        $hasOfflineBatch = $request->has('planting_logs')
            || $request->has('pest_reports')
            || $request->has('farm_profiles')
            || $request->has('field_distributions')
            || $request->has('geo_tags')
            || $request->has('geo_tag_refusals');

        if ($hasOfflineBatch) {
            try {
                DB::beginTransaction();

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

                // Fail the batch if any offline item failed validation/insert.
                $offlineFailed = collect([
                    ...$results['planting_logs'],
                    ...$results['pest_reports'],
                    ...$results['farm_profiles'],
                    ...$results['field_distributions'],
                    ...$results['geo_tags'],
                    ...$results['geo_tag_refusals'],
                ])->contains(fn ($r) => ($r['outcome'] ?? '') === 'failed');

                if ($offlineFailed) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Sync failed. Offline batch rolled back.',
                        'results' => $results,
                    ], 500);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('Offline bulk sync failed: '.$e->getMessage());

                return response()->json([
                    'status' => 'error',
                    'message' => 'Sync failed. '.$e->getMessage(),
                ], 500);
            }
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

        return $this->itemResult($clientId, $result['outcome'], $result['body']['message'] ?? '');
    }

    private function syncAssessment(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['id'] ?? ($item['client_id'] ?? null);

        $validator = Validator::make($item, [
            'farm_plot_id' => 'required|uuid|exists:farm_plots,id',
            'calamity_type' => ['required', Rule::in(['Typhoon', 'Flood', 'Drought', 'Pest Outbreak', 'Hail', 'Other'])],
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

    private function syncPlantingLog(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);

        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);

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

        $plotId = $item['farm_plot_id'] ?? null;
        if ($plotId) {
            $plot = FarmPlot::find($plotId);
            $cap = $plot ? (float) $plot->size_ha : null;
            if ($cap !== null && (float) $item['area_planted'] > $cap + 0.0001) {
                return $this->itemResult($clientId, 'failed', 'Area planted cannot exceed the farm plot size ('.$cap.' ha).');
            }
        }

        if ($clientId && PlantingLog::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Planting log already synced.');
        }

        $log = PlantingLog::create([
            'client_id' => $clientId,
            'farmer_id' => $farmerId,
            'farm_plot_id' => $item['farm_plot_id'] ?? null,
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

        return $this->itemResult($clientId ?? $log->id, 'synced', 'Planting log saved.');
    }

    private function syncPestReport(array $item, string $technicianId, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);
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

        $photoPath = null;
        if (! empty($item['photo_base64'])) {
            $photoPath = $this->storeBase64Image($item['photo_base64'], 'pest-monitoring');
            if ($photoPath === null) {
                return $this->itemResult($clientId, 'failed', 'Pest photo could not be decoded.');
            }
        }

        $payload = [
            'farmer_id' => $farmerId,
            'technician_id' => $technicianId,
            'crop' => $item['crop'] ?? null,
            'pest_name' => $item['pest_name'] ?? null,
            'incidence' => (int) $item['incidence'],
            'severity' => $item['severity'],
            'advisory' => $item['advisory'] ?? null,
            'is_outbreak' => (bool) ($item['is_outbreak'] ?? false),
            'latitude' => $item['lat'] ?? ($item['latitude'] ?? null),
            'longitude' => $item['lng'] ?? ($item['longitude'] ?? null),
            'report_ref' => $item['report_id'] ?? null,
            'item_distributed' => $item['item_distributed'] ?? null,
            'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : null,
            'device_id' => $item['device_id'] ?? $deviceId,
        ];
        if ($photoPath) {
            $payload['photo_path'] = $photoPath;
        }

        if ($serverId) {
            $existing = PestMonitoring::find($serverId);
            if ($existing) {
                $existing->update($payload);

                return $this->itemResult($clientId ?? $existing->id, 'synced', 'Pest report updated.');
            }
        }

        if ($clientId && PestMonitoring::where('client_id', $clientId)->exists()) {
            return $this->itemResult($clientId, 'duplicate', 'Pest report already synced.');
        }

        $row = PestMonitoring::create(array_merge($payload, [
            'client_id' => $clientId,
            'photo_path' => $photoPath,
        ]));

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

        return $this->itemResult($clientId, 'synced', 'Field distribution saved.');
    }

    private function syncFarmProfile(array $item, ?string $deviceId): array
    {
        $clientId = $item['client_id'] ?? ($item['id'] ?? null);
        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);

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
        $plot->save();

        DB::update(
            'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
            [$lng, $lat, $plot->id]
        );

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

        $validator = Validator::make($item, [
            'geometry_type' => ['required', Rule::in(['polygon', 'marker'])],
            'coordinates' => 'required',
            'crop_planted' => 'nullable|string|max:100',
            'incident_type' => ['nullable', Rule::in(['none', 'pest', 'calamity'])],
            'non_productive_area_sqm' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->itemResult($clientId, 'failed', $validator->errors()->first());
        }

        $geometryType = $item['geometry_type'];
        $points = $this->parseGeoTagPoints($item['coordinates'] ?? null, $geometryType);

        if ($points === null || ($geometryType === 'polygon' && count($points) < 3)) {
            return $this->itemResult($clientId, 'failed', 'Invalid geo-tag coordinates payload.');
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
            $collision = $this->polygonIntegrity->findOverlappingPlot($points);
            if ($collision !== null) {
                return $this->itemResult(
                    $clientId,
                    'failed',
                    'Validation Failed: Polygon overlaps with an existing farm boundary. Please adjust coordinates.',
                );
            }
        }

        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);

        $photoPath = null;
        if (! empty($item['photo_base64'])) {
            $photoPath = $this->storeBase64Image($item['photo_base64'], 'geo-tags');
        }

        $farmerSignaturePath = null;
        if (! empty($item['farmer_signature_base64'])) {
            $farmerSignaturePath = $this->storeBase64Image($item['farmer_signature_base64'], 'geo-tags/signatures');
        }

        $aewSignaturePath = null;
        if (! empty($item['aew_signature_base64'])) {
            $aewSignaturePath = $this->storeBase64Image($item['aew_signature_base64'], 'geo-tags/signatures');
        }

        $nonProductiveSqm = (float) ($item['non_productive_area_sqm'] ?? 0);
        $grossAreaSqm = $geometryType === 'polygon' ? $this->polygonAreaSqm($points) : null;
        $finalAreaSqm = $grossAreaSqm !== null ? max(0.0, $grossAreaSqm - $nonProductiveSqm) : null;
        $finalAreaHa = $finalAreaSqm !== null ? round($finalAreaSqm / 10000, 4) : null;
        $hasDiscrepancy = (bool) ($item['has_discrepancy'] ?? false);

        try {
            $geoTag = GeoTag::create([
                'client_id' => $clientId,
                'farmer_id' => $farmerId,
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

            if ($geometryType === 'polygon' && $farmerId && $finalAreaHa !== null && $finalAreaHa > 0) {
                try {
                    $farmPlot = $this->createFarmPlotFromBoundary(
                        $farmerId,
                        $points,
                        $finalAreaHa,
                        $nonProductiveSqm,
                        $hasDiscrepancy,
                        $item,
                    );
                } catch (\InvalidArgumentException $e) {
                    return $this->itemResult($clientId, 'failed', $e->getMessage());
                }

                $geoTag->farm_plot_id = $farmPlot->id;
                $geoTag->save();

                if ((bool) ($item['notify_sms'] ?? true)) {
                    $this->sendGeoreferencingReceipt($farmPlot, $geoTag);
                }
            }

            return $this->itemResult($clientId ?? $geoTag->id, 'synced', 'Geo-tag saved.');
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

        $farmerId = $this->resolveFarmerId($item['farmer_id'] ?? null, $item['rsbsa_no'] ?? null);

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

        $existing = FarmPlot::query()
            ->where('farmer_id', $farmerId)
            ->whereRaw('LOWER(commodity) = ?', [strtolower((string) $commodity)])
            ->orderBy('created_at')
            ->first();
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
     * Fire the DA-RSBSA "Georeferencing Stub" SMS receipt once a farm boundary
     * has been successfully mapped and saved. Wrapped in try/catch so a
     * gateway failure never breaks the sync batch.
     */
    private function sendGeoreferencingReceipt(FarmPlot $farmPlot, GeoTag $geoTag): void
    {
        $farmer = $farmPlot->farmer ?? Farmer::find($farmPlot->farmer_id);
        if (! $farmer || empty($farmer->mobile_number)) {
            return;
        }

        try {
            $name = trim($farmer->first_name.' '.$farmer->surname);
            $areaHa = number_format((float) $farmPlot->size_ha, 4);
            $coords = number_format((float) $farmPlot->latitude, 5).', '.number_format((float) $farmPlot->longitude, 5);
            $discrepancyNote = $geoTag->has_discrepancy
                ? ' A spatial discrepancy was flagged for MAO review.'
                : '';

            $message = "AGRI-AKAP Georeferencing Stub: Hi {$name}, your farm ({$areaHa} ha) at {$coords}, "
                ."{$farmPlot->location_brgy} has been successfully mapped and verified under the RSBSA protocol."
                .$discrepancyNote;

            $this->sms->send($farmer->mobile_number, $message);

            $farmPlot->forceFill(['georef_sms_sent_at' => now()])->save();
            $geoTag->forceFill(['sms_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Georeferencing SMS receipt failed: '.$e->getMessage());
        }
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

            return null;
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
     * Accept UUID farmer_id or resolve via RSBSA number.
     */
    private function resolveFarmerId(?string $farmerId, ?string $rsbsaNo = null): ?string
    {
        if ($farmerId && Str::isUuid($farmerId) && Farmer::whereKey($farmerId)->exists()) {
            return $farmerId;
        }

        $lookup = $rsbsaNo ?: $farmerId;
        if ($lookup) {
            return Farmer::where('rsbsa_no', $lookup)->value('id');
        }

        return null;
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

    private function itemResult(?string $clientId, string $outcome, string $message): array
    {
        return [
            'client_id' => $clientId,
            'outcome' => $outcome, // synced | duplicate | failed
            'message' => $message,
        ];
    }
}
