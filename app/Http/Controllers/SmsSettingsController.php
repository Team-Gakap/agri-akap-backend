<?php

namespace App\Http\Controllers;

use App\Services\SmsService;
use App\Services\SystemAuditLogger;
use App\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsSettingsController extends Controller
{
    public function __construct(
        private SmsService $sms,
        private SystemSettingService $settings,
        private SystemAuditLogger $audit,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'SMS gateway settings retrieved.',
            'data' => $this->payload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|in:'.implode(',', SystemSettingService::SMS_PROVIDERS),
        ]);

        $provider = $validated['provider'];

        if (! $this->sms->canDispatch($provider)) {
            $label = $provider === SystemSettingService::PROVIDER_SEMAPHORE
                ? 'Semaphore API key'
                : 'IPROG API token';

            return response()->json([
                'status' => 'error',
                'message' => "{$label} is not configured on the server.",
            ], 422);
        }

        $before = $this->settings->smsProvider();
        $sourceBefore = $this->settings->smsProviderSource();

        $this->settings->set(SystemSettingService::SMS_PROVIDER_KEY, $provider);

        if ($before !== $provider || $sourceBefore !== 'database') {
            $this->audit->record('sms.provider.updated', $request->user(), null, [
                'before' => $before,
                'after' => $provider,
                'source_before' => $sourceBefore,
            ], $request);
        }

        return response()->json([
            'status' => 'success',
            'message' => $provider === SystemSettingService::PROVIDER_SEMAPHORE
                ? 'SMS gateway set to Semaphore.'
                : 'SMS gateway set to IPROG.',
            'data' => $this->payload(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'provider' => $this->sms->provider(),
            'source' => $this->settings->smsProviderSource(),
            'gateways' => [
                SystemSettingService::PROVIDER_IPROG => [
                    'configured' => $this->sms->hasCredentials(SystemSettingService::PROVIDER_IPROG),
                ],
                SystemSettingService::PROVIDER_SEMAPHORE => [
                    'configured' => $this->sms->hasCredentials(SystemSettingService::PROVIDER_SEMAPHORE),
                ],
            ],
        ];
    }
}
