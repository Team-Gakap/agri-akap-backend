<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\SmsBroadcast;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

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
        $broadcasts = SmsBroadcast::orderBy('created_at', 'desc')->take(20)->get();
        return response()->json(['status' => 'success', 'data' => $broadcasts], 200);
    }

    /**
     * Estimate matching farmers for the current audience filters (no send).
     */
    public function previewAudience(Request $request): JsonResponse
    {
        [$query] = $this->audienceQuery($request->validate([
            'target_barangay' => 'nullable|string',
            'target_barangays' => 'nullable|array',
            'target_barangays.*' => 'string|max:128',
            'target_commodity' => 'nullable|string',
            'farmer_ids' => 'nullable|array',
            'farmer_ids.*' => 'uuid|exists:farmers,id',
        ]));

        return response()->json([
            'status' => 'success',
            'data' => [
                'recipient_count' => $query->count(),
            ],
        ]);
    }

    /**
     * Send a bulk SMS to filtered farmers via Semaphore API.
     */
    public function sendBulkSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_body' => 'required|string|max:459',
            'target_barangay' => 'nullable|string',
            'target_barangays' => 'nullable|array',
            'target_barangays.*' => 'string|max:128',
            'target_commodity' => 'nullable|string',
            'farmer_ids' => 'nullable|array',
            'farmer_ids.*' => 'uuid|exists:farmers,id',
        ]);

        [$query, $barangays, $commodity] = $this->audienceQuery($validated);
        $barangayLabel = empty($barangays) ? 'All' : implode(', ', $barangays);

        $farmers = $query->get(['id', 'first_name', 'surname', 'permanent_brgy', 'mobile_number']);
        $phoneNumbers = $farmers->pluck('mobile_number')->filter()->values()->all();
        $recipientCount = count($phoneNumbers);

        if ($recipientCount === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No farmers found with valid contact numbers for this target group.',
            ], 400);
        }

        $template = $validated['message_body'];
        $personalized = $this->hasMergeTags($template);

        if ($personalized) {
            $result = $this->sendPersonalized($farmers, $template, $commodity);
            $recipientCount = (int) ($result['recipients'] ?? $recipientCount);
        } else {
            $result = $this->sms->sendBulk($phoneNumbers, $template);
        }

        $status = $result['success']
            ? SmsBroadcast::STATUS_SENT
            : SmsBroadcast::STATUS_FAILED;

        $broadcast = SmsBroadcast::create([
            'target_barangay' => $barangayLabel,
            'target_commodity' => $commodity !== '' ? $commodity : 'All',
            'message_body' => $template,
            'trigger_type' => SmsBroadcast::TRIGGER_MANUAL,
            'recipient_count' => $recipientCount,
            'status' => $status,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Broadcast processed. Queued to $recipientCount farmers.",
            'data' => $broadcast,
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Builder, 1: array<int, string>, 2: string}
     */
    private function audienceQuery(array $validated): array
    {
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

        $query = Farmer::query()
            ->whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '');

        $farmerIds = collect($validated['farmer_ids'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($farmerIds)) {
            $query->whereIn('id', $farmerIds);
        }

        if (! empty($barangays)) {
            $query->whereIn('permanent_brgy', $barangays);
        }

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

        return [$query, $barangays, $commodity];
    }

    private function hasMergeTags(string $message): bool
    {
        return (bool) preg_match('/\{(Farmer_Name|Barangay|Program_Name|Distribution_Date)\}/', $message);
    }

    /**
     * @param  Collection<int, Farmer>  $farmers
     * @return array{success: bool, recipients: int, provider: string, raw: mixed}
     */
    private function sendPersonalized(Collection $farmers, string $template, string $commodity): array
    {
        $ok = 0;
        $fail = 0;
        $last = null;

        foreach ($farmers as $farmer) {
            $number = trim((string) $farmer->mobile_number);
            if ($number === '') {
                continue;
            }
            $body = $this->interpolate($template, $farmer, $commodity);
            $last = $this->sms->send($number, $body);
            if (! empty($last['success'])) {
                $ok++;
            } else {
                $fail++;
            }
        }

        return [
            'success' => $fail === 0 && $ok > 0,
            'recipients' => $ok,
            'provider' => $last['provider'] ?? 'sms',
            'raw' => ['personalized' => true, 'sent' => $ok, 'failed' => $fail],
        ];
    }

    private function interpolate(string $template, Farmer $farmer, string $commodity): string
    {
        $name = trim(implode(' ', array_filter([
            $farmer->first_name,
            $farmer->surname,
        ]))) ?: 'Farmer';

        $program = ($commodity !== '' && strcasecmp($commodity, 'All') !== 0)
            ? $commodity.' Program'
            : 'MAO Subsidy Program';

        $date = Carbon::now('Asia/Manila')->format('F j, Y');

        return strtr($template, [
            '{Farmer_Name}' => $name,
            '{Barangay}' => (string) ($farmer->permanent_brgy ?: 'your barangay'),
            '{Program_Name}' => $program,
            '{Distribution_Date}' => $date,
        ]);
    }
}
