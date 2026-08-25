<?php

namespace App\Http\Controllers;

use App\Models\FarmPlot;
use App\Models\Farmer;
use App\Services\FarmAreaBudgetService;
use App\Services\PolygonIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FarmPlotController extends Controller
{
    /** Collision radius in meters — blocks double-claim of the same parcel (centroid-only path). */
    private const COLLISION_RADIUS_METERS = 15;

    public function __construct(
        private readonly PolygonIntegrityService $polygonIntegrity,
        private readonly FarmAreaBudgetService $farmAreaBudget,
    ) {}


    /**
     * List farm plots, optionally scoped to a single farmer.
     * Powers the damage-assessment plot picker and offline caching.
     */
    public function index(Request $request): JsonResponse
    {
        $farmerWith = 'farmer:id,first_name,middle_name,surname,ext_name,rsbsa_no,permanent_brgy';

        if ($request->boolean('geotag_queue')) {
            $user = $request->user();
            $query = FarmPlot::with([$farmerWith, 'assignedTechnician:id,name'])
                ->pendingFieldGeotag();

            if ($user?->role === 'technician') {
                $query->where('geotag_assigned_user_id', $user->id);
            }

            $plots = $query
                ->orderByRaw("CASE WHEN geotag_priority = 'urgent' THEN 0 ELSE 1 END")
                ->orderBy('geotag_deadline')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Geo-tag queue retrieved.',
                'data' => $plots,
            ], 200);
        }

        $plots = FarmPlot::with($farmerWith)
            ->when($request->filled('farmer_id'), function ($query) use ($request) {
                $query->where('farmer_id', $request->query('farmer_id'));
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Farm plots retrieved.',
            'data' => $plots,
        ], 200);
    }

    /**
     * Show a single farm plot with its owner.
     */
    public function show(string $id): JsonResponse
    {
        $plot = FarmPlot::with('farmer')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $plot,
        ], 200);
    }

    /**
     * Register a geotagged farm plot from the technician mobile app.
     *
     * Accepts an optional `coordinates` polygon array `[{lat, lng}, ...]`. When
     * supplied the endpoint runs two DA-RSBSA polygon integrity checks before
     * persisting, and stores the boundary for future overlap detection:
     *
     *   1. Start/End Gap Rule  — first and last vertices must be ≤ 10 m apart.
     *   2. Spatial Overlap Rule — new boundary must not intersect any existing plot.
     *
     * When `coordinates` is omitted the legacy centroid-only 15 m collision guard
     * still applies (backward compatible).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farmer_id'                   => 'required|uuid|exists:farmers,id',
            'latitude'                    => 'required|numeric|between:-90,90',
            'longitude'                   => 'required|numeric|between:-180,180',
            'location_brgy'               => 'required|string|max:100',
            'location_city'               => 'nullable|string|max:100',
            'location_province'           => 'nullable|string|max:100',
            'ownership_type'              => 'required|in:Owner,Tenant,Lessee,Registered Owner',
            'landowner_name'              => 'nullable|string|max:255',
            'size_ha'                     => 'required|numeric|min:0.0001|max:9999',
            'commodity'                   => 'required|string|max:100',
            'farm_type'                   => 'nullable|string|max:100',
            'proof_of_ownership_document' => 'nullable|string|max:100',
            'remarks'                     => 'nullable|string|max:1000',
            // Optional boundary polygon — enables full spatial integrity checks.
            'coordinates'                 => 'nullable|array|min:3',
            'coordinates.*.lat'           => 'required_with:coordinates|numeric|between:-90,90',
            'coordinates.*.lng'           => 'required_with:coordinates|numeric|between:-180,180',
        ]);

        $lat    = (float) $validated['latitude'];
        $lng    = (float) $validated['longitude'];
        $points = null;

        // ── Polygon integrity checks (when full boundary is supplied) ──────────
        if (! empty($validated['coordinates'])) {
            $points = $this->polygonIntegrity->normalisePoints($validated['coordinates']);

            if ($points === null) {
                return response()->json([
                    'error'   => 'Invalid Coordinates',
                    'message' => 'Validation Failed: The coordinates array could not be parsed into a valid polygon.',
                ], 422);
            }

            // 1. DA Start/End Gap Rule
            if ($this->polygonIntegrity->hasUnclosedGap($points)) {
                $first = $points[0];
                $last  = $points[count($points) - 1];
                $gapM  = round($this->polygonIntegrity->haversineMeters(
                    $first['lat'], $first['lng'], $last['lat'], $last['lng'],
                ));

                return response()->json([
                    'error'   => 'Unclosed Polygon',
                    'message' => "Validation Failed: The start and end points of the perimeter walk are {$gapM} m apart. "
                        . 'DA guidelines require ≤ ' . PolygonIntegrityService::GAP_LIMIT_METERS . ' m. '
                        . 'Please walk back to the starting stake before completing the boundary.',
                ], 422);
            }

            // 2. DA Spatial Overlap Rule
            $collision = $this->polygonIntegrity->findOverlappingPlot($points);
            if ($collision !== null) {
                return response()->json([
                    'error'   => 'Polygon Overlap',
                    'message' => 'Validation Failed: Polygon overlaps with an existing farm boundary. Please adjust coordinates.',
                ], 422);
            }
        } else {
            // Legacy centroid-only guard for backward compatibility.
            if ($this->hasCoordinateCollision($lat, $lng)) {
                return response()->json([
                    'error'   => 'Coordinate Collision',
                    'message' => 'This land parcel is already registered to another user. Please verify tenancy/ownership.',
                ], 409);
            }
        }

        $ownership = $validated['ownership_type'] === 'Owner'
            ? 'Registered Owner'
            : $validated['ownership_type'];

        $landownerName = $validated['landowner_name'] ?? null;
        $size = (float) $validated['size_ha'];

        $farmer = Farmer::findOrFail($validated['farmer_id']);
        $budgetError = $this->farmAreaBudget->assertWithinBudget($farmer, $size);
        if ($budgetError) {
            return $budgetError;
        }

        $plot = DB::transaction(function () use ($validated, $lat, $lng, $ownership, $landownerName, $points, $size) {
            $id   = (string) Str::uuid();

            DB::table('farm_plots')->insert([
                'id'                          => $id,
                'farmer_id'                   => $validated['farmer_id'],
                'location_brgy'               => $validated['location_brgy'],
                'location_city'               => $validated['location_city'] ?? 'Echague',
                'location_province'           => $validated['location_province'] ?? 'Isabela',
                'latitude'                    => $lat,
                'longitude'                   => $lng,
                'total_parcel_area_ha'        => $size,
                'is_ancestral_domain'         => false,
                'is_agrarian_reform_beneficiary' => false,
                'ownership_type'              => $ownership,
                'landowner_name'              => $landownerName,
                'proof_of_ownership_document' => $validated['proof_of_ownership_document'] ?? 'Geotag Field Capture',
                'commodity'                   => $validated['commodity'],
                'size_ha'                     => $size,
                'farm_type'                   => $validated['farm_type'] ?? 'Irrigated',
                'is_organic'                  => false,
                'remarks'                     => $validated['remarks'] ?? null,
                // Persist boundary polygon for future overlap detection.
                'boundary_points'             => $points !== null ? json_encode($points) : null,
                'geotag_status'               => 'mapped',
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);

            DB::update(
                'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
                [$lng, $lat, $id],
            );

            return FarmPlot::with('farmer:id,first_name,surname,rsbsa_no,permanent_brgy')->findOrFail($id);
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Farm plot geotagged successfully.',
            'data'    => $plot,
        ], 201);
    }

    /**
     * Update an existing farm plot (admin parcel edit, desktop geo-tag, or
     * technician field-walk sync onto a dispatched parcel).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $plot = FarmPlot::with('farmer')->findOrFail($id);
        $user = $request->user();
        $role = $user?->role;

        if ($role === 'technician') {
            if ($plot->geotag_assigned_user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This parcel is not assigned to you for geo-tagging.',
                ], 403);
            }
        } elseif ($role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to update farm plots.',
            ], 403);
        }

        $validated = $request->validate([
            'parcel_name' => 'sometimes|nullable|string|max:100',
            'location_brgy' => 'sometimes|string|max:100',
            'location_city' => 'sometimes|nullable|string|max:100',
            'location_province' => 'sometimes|nullable|string|max:100',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'georef_id' => 'sometimes|nullable|string|max:100',
            'total_parcel_area_ha' => 'sometimes|nullable|numeric|min:0.0001|max:9999',
            'size_ha' => 'sometimes|numeric|min:0.0001|max:9999',
            'ownership_type' => 'sometimes|in:Owner,Tenant,Lessee,Registered Owner,Others',
            'landowner_name' => 'sometimes|nullable|string|max:255',
            'land_owner_first_name' => 'sometimes|nullable|string|max:100',
            'land_owner_surname' => 'sometimes|nullable|string|max:100',
            'land_owner_ext_name' => 'sometimes|nullable|string|max:10',
            'proof_of_ownership_document' => 'sometimes|nullable|string|max:100',
            'commodity' => 'sometimes|string|max:100',
            'farm_type' => 'sometimes|nullable|string|max:100',
            'remarks' => 'sometimes|nullable|string|max:1000',
            'boundary_points' => 'sometimes|nullable|array|min:3',
            'boundary_points.*.lat' => 'required_with:boundary_points|numeric|between:-90,90',
            'boundary_points.*.lng' => 'required_with:boundary_points|numeric|between:-180,180',
            'geotag_status' => 'sometimes|in:unmapped,pending_field,mapped',
            'geotag_assigned_user_id' => 'sometimes|nullable|uuid|exists:users,id',
            'geotag_assigned_name' => 'sometimes|nullable|string|max:255',
            'geotag_priority' => 'sometimes|nullable|in:urgent,routine',
            'geotag_notes' => 'sometimes|nullable|string|max:2000',
            'geotag_deadline' => 'sometimes|nullable|date',
        ]);

        $points = null;
        if (array_key_exists('boundary_points', $validated) && ! empty($validated['boundary_points'])) {
            $points = $this->polygonIntegrity->normalisePoints($validated['boundary_points']);
            if ($points === null) {
                return response()->json([
                    'error' => 'Invalid Coordinates',
                    'message' => 'The boundary could not be parsed into a valid polygon.',
                ], 422);
            }
            if ($this->polygonIntegrity->hasUnclosedGap($points)) {
                return response()->json([
                    'error' => 'Unclosed Polygon',
                    'message' => 'Start and end points of the boundary are more than '
                        .PolygonIntegrityService::GAP_LIMIT_METERS.' m apart.',
                ], 422);
            }
            $collision = $this->polygonIntegrity->findOverlappingPlot($points, $plot->id);
            if ($collision !== null) {
                return response()->json([
                    'error' => 'Polygon Overlap',
                    'message' => 'Polygon overlaps with an existing farm boundary.',
                ], 422);
            }
            $validated['boundary_points'] = $points;
            $centroid = $this->polygonCentroid($points);
            $validated['latitude'] = $centroid['lat'];
            $validated['longitude'] = $centroid['lng'];
        }

        $lat = array_key_exists('latitude', $validated)
            ? ($validated['latitude'] !== null ? (float) $validated['latitude'] : null)
            : (float) $plot->latitude;
        $lng = array_key_exists('longitude', $validated)
            ? ($validated['longitude'] !== null ? (float) $validated['longitude'] : null)
            : (float) $plot->longitude;

        if ($points === null && $lat !== null && $lng !== null
            && (abs($lat) > 0.0001 || abs($lng) > 0.0001)
            && ($request->has('latitude') || $request->has('longitude'))) {
            if ($this->hasCoordinateCollision($lat, $lng, $plot->id)) {
                return response()->json([
                    'error' => 'Coordinate Collision',
                    'message' => 'This land parcel is already registered to another user. Please verify tenancy/ownership.',
                ], 409);
            }
        }

        if (isset($validated['ownership_type']) && $validated['ownership_type'] === 'Owner') {
            $validated['ownership_type'] = 'Registered Owner';
        }

        if (isset($validated['size_ha']) || isset($validated['total_parcel_area_ha'])) {
            $size = (float) ($validated['size_ha'] ?? $validated['total_parcel_area_ha'] ?? $plot->size_ha);
            $validated['size_ha'] = $size;
            $validated['total_parcel_area_ha'] = $validated['total_parcel_area_ha'] ?? $size;
            $farmer = $plot->farmer ?? Farmer::findOrFail($plot->farmer_id);
            $budgetError = $this->farmAreaBudget->assertWithinBudget($farmer, $size, $plot->id);
            if ($budgetError) {
                return $budgetError;
            }
        }

        $dispatching = $request->has('geotag_assigned_user_id')
            || $request->has('geotag_assigned_name')
            || ($validated['geotag_status'] ?? null) === 'pending_field';

        if ($dispatching && ($validated['geotag_status'] ?? $plot->geotag_status) !== 'mapped') {
            $hasAssignee = filled($validated['geotag_assigned_user_id'] ?? null)
                || filled($validated['geotag_assigned_name'] ?? null);
            if ($hasAssignee) {
                $validated['geotag_status'] = 'pending_field';
            }
        }

        $willHaveEvidence = filled($validated['georef_id'] ?? $plot->georef_id)
            || ! empty($validated['boundary_points'] ?? $plot->boundary_points)
            || ($lat !== null && $lng !== null && (abs($lat) > 0.0001 || abs($lng) > 0.0001));
        if ($willHaveEvidence || ($validated['geotag_status'] ?? null) === 'mapped') {
            $validated['geotag_status'] = 'mapped';
        }

        $plot->fill($validated);
        $plot->save();

        if ($lat !== null && $lng !== null && ($request->has('latitude') || $request->has('longitude') || $points !== null)) {
            DB::update(
                'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
                [$lng, $lat, $plot->id],
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Farm plot updated.',
            'data' => $plot->fresh(['farmer', 'assignedTechnician:id,name']),
        ]);
    }

    /**
     * Soft-delete a farm plot (admin cleanup of legacy duplicate inserts).
     */
    public function destroy(string $id): JsonResponse
    {
        $plot = FarmPlot::findOrFail($id);
        $plot->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Farm plot removed.',
            'data' => ['id' => $id],
        ]);
    }

    /**
     * True when any existing plot's coordinates are within COLLISION_RADIUS_METERS.
     */
    private function hasCoordinateCollision(float $latitude, float $longitude, ?string $excludePlotId = null): bool
    {
        $sql = 'SELECT id
             FROM farm_plots
             WHERE deleted_at IS NULL
               AND coordinates IS NOT NULL
               AND ST_Distance_Sphere(coordinates, POINT(?, ?)) <= ?'
            .($excludePlotId ? ' AND id != ?' : '')
            .' LIMIT 1';

        $bindings = $excludePlotId
            ? [$longitude, $latitude, self::COLLISION_RADIUS_METERS, $excludePlotId]
            : [$longitude, $latitude, self::COLLISION_RADIUS_METERS];

        $hit = DB::selectOne($sql, $bindings);

        return $hit !== null;
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $points
     * @return array{lat: float, lng: float}
     */
    private function polygonCentroid(array $points): array
    {
        $n = count($points);
        $lat = 0.0;
        $lng = 0.0;
        foreach ($points as $p) {
            $lat += (float) $p['lat'];
            $lng += (float) $p['lng'];
        }

        return ['lat' => $lat / max(1, $n), 'lng' => $lng / max(1, $n)];
    }
}
