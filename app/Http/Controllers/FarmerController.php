<?php

namespace App\Http\Controllers;

use App\Imports\FarmersImport;
use App\Models\Farmer;
use App\Models\PlantingLog;
use App\Http\Requests\StoreFarmerRequest;
use App\Http\Requests\UpdateFarmerRequest;
use App\Services\CropStageService;
use App\Services\FarmAreaBudgetService;
use App\Services\SmsService;
use App\Support\OfficialBarangays;
use App\Traits\DecodesBase64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FarmerController extends Controller
{
    use DecodesBase64Image;

    public function __construct(
        private SmsService $sms,
        private FarmAreaBudgetService $farmAreaBudget,
        private CropStageService $cropStages,
    ) {
    }

    /**
     * Retrieve paginated RSBSA farmer registry with role-based scoping.
     *
     * - admin: all farmers
     * - barangay_official / barangay: only assigned_barangay
     * - technician: all farmers; search optimized for rsbsa_no / surname
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user?->role;
        $searchQuery = trim((string) $request->query('search', ''));
        $barangay = $request->query('barangay');
        $commodity = trim((string) $request->query('commodity', ''));

        $query = Farmer::withCount('farmPlots')
            ->withSum('farmPlots as mapped_area_ha', 'size_ha')
            ->withCount([
                'farmPlots as georef_plots_count' => function ($q) {
                    $q->mapped();
                },
                'farmPlots as pending_geotag_count' => function ($q) {
                    $q->pendingFieldGeotag();
                },
            ]);

        if (in_array($role, ['barangay_official', 'barangay'], true)) {
            $assigned = $user->assigned_barangay;
            if (empty($assigned)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No barangay assignment on this account.',
                ], 403);
            }
            $query->where('permanent_brgy', $assigned);
        } elseif ($user?->isMunicipalAdmin()) {
            // Full registry — optional barangay filter still allowed
            $query->when($barangay, fn ($q, $b) => $q->where('permanent_brgy', $b));
        } elseif ($role === 'technician') {
            // Field search across Echague; keep barangay filter optional
            $query->when($barangay, fn ($q, $b) => $q->where('permanent_brgy', $b));
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to view the farmer registry.',
            ], 403);
        }

        // Barangay crop forms: only farmers with a matching farm-plot commodity
        if ($commodity !== '') {
            $query->whereHas('farmPlots', function ($q) use ($commodity) {
                $key = Str::lower($commodity);
                if (in_array($key, ['high-value', 'high-value crops', 'hvc'], true)) {
                    $q->where(function ($inner) {
                        $inner->whereRaw('LOWER(commodity) like ?', ['%high-value%'])
                            ->orWhereRaw('LOWER(commodity) like ?', ['%hvc%']);
                    });
                    return;
                }
                $q->whereRaw('LOWER(commodity) = ?', [$key]);
            });
        }

        $verificationStatus = trim((string) $request->query('verification_status', ''));
        if (in_array($verificationStatus, ['pending', 'approved', 'rts'], true)) {
            $query->where('verification_status', $verificationStatus);
        }

        if ($request->boolean('duplicate')) {
            $query->where('is_probable_duplicate', true);
        }

        if ($request->boolean('area_mismatch')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(size_ha), 0) FROM farm_plots WHERE farm_plots.farmer_id = farmers.id AND farm_plots.deleted_at IS NULL) > COALESCE(farmers.total_farm_area_ha, 0) + 0.0001'
            );
        }

        if ($request->has('georeferenced') && $request->query('georeferenced') !== '') {
            if ($request->boolean('georeferenced')) {
                $query->whereHas('farmPlots', fn ($q) => $q->mapped());
            } else {
                $query->whereDoesntHave('farmPlots', fn ($q) => $q->mapped());
            }
        }

        if ($request->boolean('pending_geotag')) {
            $query->whereHas('farmPlots', fn ($q) => $q->pendingFieldGeotag());
        }

        if ($request->has('has_photo') && $request->query('has_photo') !== '') {
            if ($request->boolean('has_photo')) {
                $query->whereNotNull('photo_path')->where('photo_path', '!=', '');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('photo_path')->orWhere('photo_path', '');
                });
            }
        }

        if ($searchQuery !== '') {
            if ($role === 'technician') {
                $term = '%'.$searchQuery.'%';
                $query->where(function ($q) use ($term, $searchQuery) {
                    $q->where('rsbsa_no', 'like', $term)
                        ->orWhere('surname', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('middle_name', 'like', $term)
                        ->orWhereRaw(
                            "CONCAT(surname, ', ', first_name, ' ', COALESCE(middle_name, '')) LIKE ?",
                            [$term]
                        )
                        ->orWhereRaw(
                            "CONCAT(first_name, ' ', surname) LIKE ?",
                            [$term]
                        );

                    if (Str::isUuid($searchQuery)) {
                        $q->orWhere('id', $searchQuery);
                    }
                });
            } else {
                $query->search($searchQuery);
            }
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));
        $farmers = $query->orderBy('surname', 'asc')->paginate($perPage);

        $farmers->getCollection()->transform(function (Farmer $farmer) {
            $this->decorateFarmer($farmer);
            // is_rffa_eligible loads farmPlots; drop them so binary POINT coords
            // and full polygons are not dumped into the masterlist JSON.
            $farmer->makeHidden(['farmPlots', 'farm_plots']);

            return $farmer;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Farmers registry retrieved.',
            'data' => $farmers,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Bulk import the official RSBSA Excel masterlist (admin only).
     * Upserts by rsbsa_no so re-uploads update existing farmers.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        try {
            $import = new FarmersImport;
            Excel::import($import, $request->file('excel_file'));

            return response()->json([
                'status' => 'success',
                'message' => 'RSBSA masterlist imported successfully.',
                'data' => [
                    'created' => $import->created,
                    'updated' => $import->updated,
                    'skipped' => $import->skipped,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Farmer Excel import failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Import failed. Check the file format and try again.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Show a single farmer profile with their farm plots and distributions.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $farmer = Farmer::with([
            'farmPlots',
            'distributions.program:id,name,unit_of_measurement,type',
        ])->findOrFail($id);

        $this->assertBarangayFarmerAccess($request, $farmer);

        $budget = $this->farmAreaBudget->summary($farmer);
        $farmer->setAttribute('mapped_area_ha', $budget['mapped_area_ha']);
        $farmer->setAttribute('remaining_ha', $budget['remaining_ha']);
        $farmer->setAttribute('area_mismatch', $budget['area_mismatch']);
        $this->decorateFarmer($farmer, $budget['mapped_area_ha']);

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer profile retrieved.',
            'data' => $farmer,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Resolve a scanned QR value to a farmer and their plots.
     * The QR encodes the farmer UUID (see IdIssuancePage); we also fall back
     * to matching the qr_code_hash for forward compatibility.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['qr' => 'required|string']);

        $qr = trim($request->query('qr'));

        $farmer = Farmer::with('farmPlots')
            ->where(function ($q) use ($qr) {
                $q->where('id', $qr)
                    ->orWhere('qr_code_hash', $qr)
                    ->orWhere('rsbsa_no', $qr)
                    ->orWhere('transaction_code', $qr);
            })
            ->first();

        if (!$farmer) {
            return response()->json([
                'status' => 'error',
                'message' => 'No registered farmer matches this QR code.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer identified.',
            'data' => $farmer,
        ], 200);
    }

    /**
     * Live stage + details for a farmer's most recent active planting log.
     * Optional `farm_plot_id` / `commodity` narrow the match. Returns data:null
     * (200) when the farmer has no matching active planting — not a 404.
     */
    public function activePlanting(Request $request, string $id): JsonResponse
    {
        Farmer::findOrFail($id);

        $plotId = trim((string) $request->query('farm_plot_id', ''));
        $commodity = trim((string) $request->query('commodity', ''));

        $query = PlantingLog::query()
            ->where('farmer_id', $id)
            ->whereRaw('LOWER(status) = ?', ['active']);

        if ($commodity !== '') {
            $query->where('crop_type', $commodity);
        }

        if ($plotId !== '') {
            $query->where(function ($q) use ($plotId) {
                $q->where('farm_plot_id', $plotId)->orWhereNull('farm_plot_id');
            });
            $query->orderByRaw('CASE WHEN farm_plot_id IS NULL THEN 1 ELSE 0 END');
        }

        $log = $query
            ->orderByDesc('date_planted')
            ->orderByDesc('created_at')
            ->first();

        if (! $log) {
            return response()->json([
                'status' => 'success',
                'message' => 'No active planting log.',
                'data' => null,
            ]);
        }

        $stage = $this->cropStages->resolveForPlantingLog($log);

        return response()->json([
            'status' => 'success',
            'message' => 'Active planting retrieved.',
            'data' => [
                'id' => $log->id,
                'farm_plot_id' => $log->farm_plot_id,
                'commodity' => $log->crop_type,
                'variety' => $log->variety,
                'area_planted_ha' => $log->area_planted !== null ? (float) $log->area_planted : null,
                'date_planted' => optional($log->date_planted)->toDateString(),
                'computed_stage' => $stage['current_stage'] ?? null,
                'days_elapsed' => $stage['days_elapsed'] ?? null,
                'days_to_harvest' => $stage['days_to_harvest'] ?? null,
                'estimated_harvest_date' => $stage['estimated_harvest_date'] ?? null,
            ],
        ]);
    }

    /**
     * Return official Echague barangay names for filter and enrollment dropdowns.
     * Pass ?with_farmers=1 to limit to barangays that already have registered farmers.
     */
    public function barangays(Request $request): JsonResponse
    {
        $names = collect(OfficialBarangays::names());

        if ($request->boolean('with_farmers')) {
            $used = Farmer::query()
                ->distinct()
                ->orderBy('permanent_brgy')
                ->pluck('permanent_brgy')
                ->filter();
            $names = $names->intersect($used)->values();
        }

        return response()->json([
            'status' => 'success',
            'data' => $names->values(),
        ]);
    }

    /**
     * Return distinct commodities from farm plots for filter dropdowns.
     */
    public function commodities(): JsonResponse
    {
        $commodities = \App\Models\FarmPlot::distinct()
            ->orderBy('commodity')
            ->pluck('commodity')
            ->filter()
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $commodities,
        ]);
    }

    /**
     * Upload/update a farmer's ID portrait (base64 from admin or barangay issuance UI).
     * Barangay officials may only write photos for farmers in their assigned barangay.
     */
    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'photo_base64' => 'required|string',
        ]);

        $farmer = Farmer::findOrFail($id);
        $this->assertBarangayFarmerAccess($request, $farmer);
        $path = $this->storeBase64Image($request->input('photo_base64'), 'farmer-photos');

        if ($path === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Photo could not be decoded. Please recapture.',
            ], 422);
        }

        $farmer->update(['photo_path' => $path]);

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer photo saved.',
            'data' => [
                'photo_url' => public_storage_url($path),
                'photo_path' => $path,
            ],
        ]);
    }

    /**
     * Store an RSBSA-compliant farmer profile alongside nested farm plots.
     */
    public function store(StoreFarmerRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        // ── FFRS 2.0 Automated Deduplication Engine ────────────────────────────
        // Query for existing farmers matching surname + first_name + birthdate.
        // Instead of aborting, we flag `is_probable_duplicate = true` so Admin
        // staff can review and merge these via the web dashboard later.
        $duplicate = Farmer::whereRaw('LOWER(surname) = ?', [Str::lower($validatedData['surname'])])
            ->whereRaw('LOWER(first_name) = ?', [Str::lower($validatedData['first_name'])])
            ->whereDate('birthdate', $validatedData['birthdate'])
            ->exists();

        if ($duplicate) {
            $validatedData['is_probable_duplicate'] = true;
        }

        DB::beginTransaction();

        try {
            $validatedData['qr_code_hash'] = (string) Str::uuid();

            // Empty RSBSA becomes null via ConvertEmptyStringsToNull — assign one
            // so new enrollments are never saved without an RSBSA reference number.
            if (empty($validatedData['rsbsa_no'])) {
                $validatedData['rsbsa_no'] = $this->generateUniqueRsbsaNo();
            }

            // Handle optional farmer photo captured during enrollment.
            if ($request->filled('photo_base64')) {
                $path = $this->storeBase64Image($request->input('photo_base64'), 'farmer-photos');
                if ($path) {
                    $validatedData['photo_path'] = $path;
                }
            }

            $plots = $validatedData['plots'] ?? [];
            unset($validatedData['plots']);

            $validatedData['total_farm_area_ha'] = $this->farmAreaBudget->quotaFromRegistrationPlots($plots);

            $farmer = Farmer::create($validatedData);

            foreach ($plots as $plotData) {
                $farmer->farmPlots()->create($this->enrollmentPlotAttributes($plotData));
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Farmer and corresponding parcel logs enrolled successfully.',
                'data' => $farmer->load('farmPlots'),
            ], 201, [], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Database transaction failed. Record aborted.',
                'error' => app()->isLocal() ? $e->getMessage() : 'Please contact support.',
            ], 500);
        }
    }

    /**
     * Local Echague RSBSA reference: IV-02-0423-{YEAR}-{SEQ}.
     * Matches FarmerSeeder convention; sequential and unique (incl. soft-deleted).
     */
    protected function generateUniqueRsbsaNo(): string
    {
        $prefix = 'IV-02-0423-'.now()->year.'-';

        $maxSeq = (int) Farmer::withTrashed()
            ->where('rsbsa_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(SUBSTRING_INDEX(rsbsa_no, "-", -1) AS UNSIGNED)) as max_seq')
            ->value('max_seq');

        $next = $maxSeq + 1;

        do {
            $candidate = $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $exists = Farmer::withTrashed()->where('rsbsa_no', $candidate)->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }

    /**
     * DA MAO Operational UI/UX: Update farmer verification status to RTS
     * (Return for Correction) and notify the farmer via SMS with the exact
     * document issue that needs to be addressed.
     */
    public function returnForCorrection(Request $request, string $farmerId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $farmer = Farmer::findOrFail($farmerId);

        $farmer->update([
            'verification_status' => 'rts',
            'rts_reason' => $validated['reason'],
        ]);

        // Fire SMS notification to farmer about the document issue.
        $this->sendRtsNotification($farmer, $validated['reason']);

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer marked for correction. SMS notification sent.',
            'data' => $farmer,
        ]);
    }

    /**
     * Send RTS (Return for Correction) SMS notification to the farmer,
     * informing them of the exact document issue.
     */
    protected function sendRtsNotification(Farmer $farmer, string $reason): void
    {
        if (empty($farmer->mobile_number)) {
            return;
        }

        try {
            $name = trim($farmer->first_name . ' ' . $farmer->surname);
            $reference = $farmer->rsbsa_no ?: $farmer->transaction_code;

            $message = "AGRI-AKAP MAO: Hi {$name} ({$reference}), your document was returned for correction. "
                . "Issue: {$reason}. Please re-submit corrected documents at the MAO office.";

            $this->sms->send($farmer->mobile_number, $message);
        } catch (\Throwable $e) {
            Log::warning('RTS SMS notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Update an enrolled farmer (admin). Nested plots are upserted when provided.
     */
    public function update(UpdateFarmerRequest $request, string $id): JsonResponse
    {
        $farmer = Farmer::findOrFail($id);
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            if ($request->filled('photo_base64')) {
                $path = $this->storeBase64Image($request->input('photo_base64'), 'farmer-photos');
                if ($path) {
                    $validated['photo_path'] = $path;
                }
            }
            unset($validated['photo_base64']);

            $plots = $validated['plots'] ?? null;
            unset($validated['plots']);

            if (is_array($plots)) {
                $validated['total_farm_area_ha'] = $this->farmAreaBudget->quotaFromRegistrationPlots($plots);
                foreach ($plots as $plotData) {
                    $plotId = $plotData['id'] ?? null;
                    $plotData = $this->enrollmentPlotAttributes($plotData);
                    if ($plotId) {
                        $plot = $farmer->farmPlots()->where('id', $plotId)->first();
                        if ($plot) {
                            $plot->update($plotData);
                            continue;
                        }
                    }
                    $farmer->farmPlots()->create($plotData);
                }
            }

            $farmer->update($validated);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Could not update farmer record.',
                'error' => app()->isLocal() ? $e->getMessage() : 'Please contact support.',
            ], 500);
        }

        $farmer->refresh()->load('farmPlots');
        $budget = $this->farmAreaBudget->summary($farmer);
        $farmer->setAttribute('mapped_area_ha', $budget['mapped_area_ha']);
        $farmer->setAttribute('remaining_ha', $budget['remaining_ha']);
        $farmer->setAttribute('area_mismatch', $budget['area_mismatch']);
        $this->decorateFarmer($farmer, $budget['mapped_area_ha']);

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer record updated.',
            'data' => $farmer,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Soft-delete / archive a farmer (admin).
     */
    public function destroy(string $id): JsonResponse
    {
        $farmer = Farmer::findOrFail($id);
        $farmer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer record archived.',
            'data' => ['id' => $id],
        ]);
    }

    /**
     * Mark a farmer as document-verified (admin).
     */
    public function verify(string $id): JsonResponse
    {
        $farmer = Farmer::findOrFail($id);
        $farmer->update([
            'verification_status' => 'approved',
            'rts_reason' => null,
            'verified_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Farmer marked as verified.',
            'data' => $farmer->fresh(),
        ]);
    }

    /**
     * Send a one-off SMS to a single farmer (admin).
     */
    public function notify(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:160',
        ]);

        $farmer = Farmer::findOrFail($id);
        if (empty($farmer->mobile_number)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This farmer has no mobile number on file.',
            ], 422);
        }

        $result = $this->sms->send($farmer->mobile_number, $validated['message']);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['success']
                ? 'SMS queued to the farmer.'
                : 'SMS could not be sent. Check the gateway configuration.',
            'data' => ['farmer_id' => $farmer->id],
        ], $result['success'] ? 200 : 502);
    }

    private function decorateFarmer(Farmer $farmer, ?float $mappedHa = null): Farmer
    {
        $mapped = $mappedHa ?? (float) ($farmer->mapped_area_ha ?? 0);
        $farmer->setAttribute('mapped_area_ha', $mapped);
        $farmer->setAttribute('area_mismatch', $this->farmAreaBudget->isMismatch($farmer, $mapped));
        $farmer->setAttribute(
            'remaining_ha',
            max(0.0, (float) ($farmer->total_farm_area_ha ?? 0) - $mapped)
        );

        $birth = $farmer->birthdate;
        $farmer->setAttribute('is_senior', $birth ? $birth->age >= 60 : false);

        if ($farmer->relationLoaded('farmPlots')) {
            $geo = $farmer->farmPlots->contains(function ($plot) {
                return $plot->geotag_status === 'mapped' || $plot->hasGeoTagEvidence();
            });
            $pending = $farmer->farmPlots->contains(function ($plot) {
                return $plot->geotag_status === 'pending_field' && ! $plot->hasGeoTagEvidence();
            });
        } else {
            $geo = ((int) ($farmer->georef_plots_count ?? 0)) > 0;
            $pending = ((int) ($farmer->pending_geotag_count ?? 0)) > 0;
        }
        $farmer->setAttribute('is_georeferenced', $geo);
        $farmer->setAttribute('pending_geotag', $pending);

        return $farmer;
    }

    /**
     * Barangay officials may only read/write farmers in their assigned barangay.
     * Admin, super-admin, and technician are unrestricted here (list routes still
     * apply their own filters).
     */
    private function assertBarangayFarmerAccess(Request $request, Farmer $farmer): void
    {
        $user = $request->user();
        $role = $user?->role;

        if (! in_array($role, ['barangay_official', 'barangay'], true)) {
            return;
        }

        $assigned = trim((string) ($user->assigned_barangay ?? ''));
        if ($assigned === '') {
            abort(response()->json([
                'status' => 'error',
                'message' => 'No barangay assignment on this account.',
            ], 403));
        }

        if (strcasecmp(trim((string) $farmer->permanent_brgy), $assigned) !== 0) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'You can only manage farmers in your assigned barangay.',
            ], 403));
        }
    }

    /**
     * RSBSA Form 01-2024 enrollment payload only. GPS / GEOREF / geotag dispatch
     * live on FarmPlotController and must not be written (or wiped) here.
     */
    private function enrollmentPlotAttributes(array $plotData): array
    {
        unset(
            $plotData['id'],
            $plotData['locating'],
            $plotData['latitude'],
            $plotData['longitude'],
            $plotData['georef_id'],
            $plotData['coordinates'],
            $plotData['boundary_points'],
            $plotData['geotag_status'],
            $plotData['geotag_assigned_user_id'],
            $plotData['geotag_assigned_name'],
            $plotData['geotag_priority'],
            $plotData['geotag_notes'],
            $plotData['geotag_deadline']
        );

        return $plotData;
    }
}
