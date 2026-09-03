<?php

namespace App\Http\Controllers;

use App\Models\CropMonitoring;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\PestOutbreak;
use App\Models\SmsBroadcast;
use App\Services\SmsService;
use App\Traits\LogsReportAudit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntelligenceController extends Controller
{
    use LogsReportAudit;

    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Log a new crop cycle and check for Monoculture risks.
     */
    public function logCrop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farm_plot_id' => 'required|exists:farm_plots,id',
            'crop_planted' => 'required|string|max:100',
            'season' => 'required|in:Wet,Dry',
            'year' => 'required|integer|min:2000|max:2100',
            'soil_ph' => 'nullable|numeric|between:0,14',
            'area_planted_ha' => 'nullable|numeric|min:0|max:100000',
            'expected_yield_kg' => 'nullable|numeric|min:0|max:100000000',
            'actual_yield_kg' => 'nullable|numeric|min:0|max:100000000',
            'crop_stage' => 'nullable|string|max:50',
        ]);

        $validated['technician_id'] = $request->user()->id;
        $currentLog = CropMonitoring::create($validated);

        // Monoculture algorithm: fetch the last 3 plantings for this plot.
        $history = CropMonitoring::where('farm_plot_id', $validated['farm_plot_id'])
            ->orderBy('year', 'desc')
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->pluck('crop_planted')
            ->toArray();

        $monocultureWarning = count($history) === 3 && count(array_unique($history)) === 1;

        return response()->json([
            'status' => 'success',
            'message' => 'Crop cycle logged successfully.',
            'data' => $currentLog->load('farmPlot:id,location_brgy,commodity'),
            'monoculture_alert' => $monocultureWarning,
            'alert_message' => $monocultureWarning
                ? 'WARNING: This plot has planted ' . $validated['crop_planted'] . ' for 3 consecutive seasons. Soil depletion risk is high. Recommend crop rotation.'
                : null,
        ], 201);
    }

    /**
     * Crop history for a specific plot (for the intelligence dashboard).
     */
    public function cropHistory(Request $request): JsonResponse
    {
        $request->validate(['farm_plot_id' => 'required|exists:farm_plots,id']);

        $history = CropMonitoring::with('technician:id,name')
            ->where('farm_plot_id', $request->query('farm_plot_id'))
            ->orderBy('year', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $history,
        ]);
    }

    /**
     * Plots at monoculture risk (planted the same crop 3+ consecutive times).
     * Powers the crop rotation alert panel in the admin intelligence dashboard.
     */
    public function monocultureAlerts(): JsonResponse
    {
        $plots = FarmPlot::with('farmer:id,first_name,surname,permanent_brgy')->get();

        $alerts = [];
        foreach ($plots as $plot) {
            $history = CropMonitoring::where('farm_plot_id', $plot->id)
                ->orderBy('year', 'desc')
                ->orderBy('created_at', 'desc')
                ->take(3)
                ->pluck('crop_planted')
                ->toArray();

            if (count($history) >= 3 && count(array_unique($history)) === 1) {
                $alerts[] = [
                    'farm_plot_id' => $plot->id,
                    'location_brgy' => $plot->location_brgy,
                    'commodity' => $plot->commodity,
                    'size_ha' => $plot->size_ha,
                    'farmer' => $plot->farmer,
                    'repeated_crop' => $history[0],
                    'consecutive_seasons' => count($history),
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $alerts,
        ]);
    }

    /**
     * Fetch the Intelligence Dashboard Data (pest outbreaks + monoculture summary).
     */
    public function getDashboardData(): JsonResponse
    {
        $activePests = PestOutbreak::with([
            'farmPlot:id,location_brgy,commodity,farmer_id',
            'farmPlot.farmer:id,first_name,surname',
            'technician:id,name',
        ])
            ->where('status', 'Active')
            ->orderBy('date_spotted', 'desc')
            ->get();

        // Attach a community-advisory preview to High/Critical threat vectors so
        // the admin can review the target group before broadcasting.
        $activePests->each(function (PestOutbreak $pest) {
            if (in_array($pest->severity, ['High', 'Critical'], true) && $pest->farmPlot) {
                $pest->advisory = $this->buildAdvisory($pest, $pest->farmPlot);
            }
        });

        $pestSummary = [
            'total' => PestOutbreak::count(),
            'active' => PestOutbreak::where('status', 'Active')->count(),
            'contained' => PestOutbreak::where('status', 'Contained')->count(),
            'resolved' => PestOutbreak::where('status', 'Resolved')->count(),
            'by_severity' => PestOutbreak::where('status', 'Active')
                ->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_pests' => $activePests,
                'pest_summary' => $pestSummary,
            ],
        ], 200);
    }

    /**
     * Log a new pest sighting from the field. Resolves a prescriptive
     * countermeasure and flags High/Critical reports as active threat vectors.
     */
    public function reportPest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farm_plot_id' => 'required|exists:farm_plots,id',
            'pest_name' => 'required|string|max:150',
            'severity' => 'required|in:Low,Medium,High,Critical',
            'date_spotted' => 'required|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'notes' => 'nullable|string|max:500',
        ]);

        $plot = FarmPlot::with('farmer:id,permanent_brgy')->findOrFail($validated['farm_plot_id']);

        // Bind the report to the parcel's stored coordinates when the device
        // GPS fix is unavailable.
        if (empty($validated['latitude']) && !empty($plot->latitude)) {
            $validated['latitude'] = $plot->latitude;
        }
        if (empty($validated['longitude']) && !empty($plot->longitude)) {
            $validated['longitude'] = $plot->longitude;
        }

        $validated['technician_id'] = $request->user()->id;
        $validated['status'] = 'Active';
        $validated['recommended_intervention'] = $this->resolveIntervention(
            $validated['pest_name'],
            $validated['severity']
        );

        $pest = PestOutbreak::create($validated);

        $highPriority = in_array($validated['severity'], ['High', 'Critical'], true);

        return response()->json([
            'status' => 'success',
            'message' => 'Pest outbreak reported. MAO office has been notified.',
            'data' => $pest->load('farmPlot:id,location_brgy,commodity'),
            'recommended_intervention' => $validated['recommended_intervention'],
            'high_priority' => $highPriority,
            'advisory' => $highPriority ? $this->buildAdvisory($pest, $plot) : null,
        ], 201);
    }

    /**
     * Resolve the MAO-approved countermeasure for a pest signature, escalating
     * the directive for High/Critical severity. See config/pest_guidelines.php.
     */
    protected function resolveIntervention(string $pestName, string $severity): string
    {
        $interventions = config('pest_guidelines.interventions', []);
        $normalized = Str::lower(trim($pestName));

        $match = collect($interventions)
            ->first(fn ($text, $label) => Str::lower($label) === $normalized);

        $recommendation = $match ?? config('pest_guidelines.default');

        if (in_array($severity, ['High', 'Critical'], true)) {
            $recommendation .= ' ' . config('pest_guidelines.escalation');
        }

        return $recommendation;
    }

    /**
     * Build the community-advisory preview payload (target group + message)
     * for a confirmed outbreak so the admin can review before broadcasting.
     */
    protected function buildAdvisory(PestOutbreak $pest, FarmPlot $plot): array
    {
        $brgy = $plot->location_brgy;
        $commodity = $plot->commodity;

        $recipientCount = Farmer::whereNotNull('mobile_number')
            ->where('permanent_brgy', $brgy)
            ->whereHas('farmPlots', fn ($q) => $q->where('commodity', $commodity))
            ->count();

        return [
            'target_barangay' => $brgy,
            'target_commodity' => $commodity,
            'recipient_count' => $recipientCount,
            'message' => $this->advisoryMessage($pest->pest_name, $brgy, $commodity),
        ];
    }

    /**
     * Compose the <=160 character advisory SMS body.
     */
    protected function advisoryMessage(string $pestName, string $brgy, string $commodity): string
    {
        return "MAO Alert: {$pestName} detected in Brgy {$brgy}. Inspect your {$commodity} fields now and apply countermeasures. Visit the MAO office for details.";
    }

    /**
     * Update a pest outbreak status (Contained / Resolved).
     */
    public function updatePestStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['Active', 'Contained', 'Resolved'])],
            'notes' => 'nullable|string|max:500',
        ]);

        $pest = PestOutbreak::findOrFail($id);
        $pest->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => "Pest status updated to {$validated['status']}.",
            'data' => $pest->fresh(),
        ]);
    }

    /**
     * Broadcast a community advisory (IPROG /send_bulk) to all farmers of the
     * affected commodity in the outbreak's barangay. Admin-triggered.
     */
    public function broadcastAdvisory(string $id): JsonResponse
    {
        $pest = PestOutbreak::with('farmPlot:id,location_brgy,commodity')->findOrFail($id);

        if (!$pest->farmPlot) {
            return response()->json([
                'status' => 'error',
                'message' => 'The affected farm parcel could not be resolved for this outbreak.',
            ], 422);
        }

        $brgy = $pest->farmPlot->location_brgy;
        $commodity = $pest->farmPlot->commodity;

        $farmers = Farmer::whereNotNull('mobile_number')
            ->where('permanent_brgy', $brgy)
            ->whereHas('farmPlots', fn ($q) => $q->where('commodity', $commodity))
            ->get();

        $phoneNumbers = $farmers->pluck('mobile_number')->filter()->values()->all();
        $recipientCount = count($phoneNumbers);

        if ($recipientCount === 0) {
            return response()->json([
                'status' => 'error',
                'message' => "No {$commodity} farmers with contact numbers found in Brgy {$brgy}.",
            ], 400);
        }

        $message = $this->advisoryMessage($pest->pest_name, $brgy, $commodity);
        $result = $this->sms->sendBulk($phoneNumbers, $message);
        $status = $result['success']
            ? 'Success (' . $result['provider'] . ')'
            : 'Failed (' . $result['provider'] . ')';

        $broadcast = SmsBroadcast::create([
            'target_barangay' => $brgy,
            'target_commodity' => $commodity,
            'message_body' => $message,
            'recipient_count' => $recipientCount,
            'status' => $status,
        ]);

        $this->logReportAudit('pest.advisory.sent', $broadcast, [
            'after' => [
                'recipient_count' => $recipientCount,
                'message_body' => $message,
                'target_barangay' => $brgy,
                'target_commodity' => $commodity,
                'pest_outbreak_id' => $pest->id,
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Community advisory dispatched to {$recipientCount} {$commodity} farmer(s) in Brgy {$brgy}.",
            'data' => $broadcast,
        ], 200);
    }
}
