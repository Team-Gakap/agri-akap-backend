<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\SmsBroadcast;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BroadcastController extends Controller
{
    public function __construct(private SmsService $sms)
    {
    }

    /**
     * Get recent SMS campaigns for the dashboard.
     */
    public function index(): JsonResponse
    {
        $broadcasts = SmsBroadcast::orderBy('created_at', 'desc')->take(10)->get();
        return response()->json(['status' => 'success', 'data' => $broadcasts], 200);
    }

    /**
     * Send a bulk SMS to filtered farmers via Semaphore API.
     */
    public function sendBulkSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_body' => 'required|string|max:160', // Standard SMS limit
            'target_barangay' => 'nullable|string',
            'target_barangays' => 'nullable|array',
            'target_barangays.*' => 'string|max:128',
            'target_commodity' => 'nullable|string',
        ]);

        $barangays = collect($validated['target_barangays'] ?? [])
            ->map(fn ($b) => trim((string) $b))
            ->filter(fn ($b) => $b !== '' && strcasecmp($b, 'All') !== 0)
            ->unique()
            ->values()
            ->all();

        $legacyBarangay = trim((string) ($validated['target_barangay'] ?? ''));
        if ($legacyBarangay !== '' && strcasecmp($legacyBarangay, 'All') !== 0 && empty($barangays)) {
            $barangays = [$legacyBarangay];
        }

        $barangayLabel = empty($barangays) ? 'All' : implode(', ', $barangays);

        // 1. Build the query to find target farmers
        $query = Farmer::whereNotNull('mobile_number');

        if (!empty($barangays)) {
            $query->whereIn('permanent_brgy', $barangays);
        }

        // Use whereHas to filter by their farm plots' commodity
        $commodity = trim((string) ($validated['target_commodity'] ?? ''));
        if ($commodity !== '' && strcasecmp($commodity, 'All') !== 0) {
            $query->whereHas('farmPlots', function ($q) use ($commodity) {
                if (strcasecmp($commodity, 'Both') === 0) {
                    $q->where(function ($inner) {
                        $inner->whereRaw('LOWER(commodity) like ?', ['%rice%'])
                            ->orWhereRaw('LOWER(commodity) like ?', ['%corn%']);
                    });
                } else {
                    $q->where('commodity', $commodity);
                }
            });
        }

        // 2. Extract and format phone numbers
        $farmers = $query->get();
        $phoneNumbers = $farmers->pluck('mobile_number')->filter()->values()->all();
        $recipientCount = count($phoneNumbers);

        if ($recipientCount === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No farmers found with valid contact numbers for this target group.'
            ], 400);
        }

        // 3. Dispatch through the configured SMS gateway (IPROG / Semaphore).
        $result = $this->sms->sendBulk($phoneNumbers, $validated['message_body']);
        $status = $result['success']
            ? SmsBroadcast::STATUS_SENT
            : SmsBroadcast::STATUS_FAILED;

        // 4. Log the Campaign in our database
        $broadcast = SmsBroadcast::create([
            'target_barangay' => $barangayLabel,
            'target_commodity' => $commodity !== '' ? $commodity : 'All',
            'message_body' => $validated['message_body'],
            'trigger_type' => SmsBroadcast::TRIGGER_MANUAL,
            'recipient_count' => $recipientCount,
            'status' => $status,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Broadcast processed. Queued to $recipientCount farmers.",
            'data' => $broadcast
        ], 200);
    }
}
