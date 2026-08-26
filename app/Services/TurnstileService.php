<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileService
{
    public function isEnabled(): bool
    {
        return filled(config('services.turnstile.secret'));
    }

    /**
     * Captcha is required on the public web portal. Native Capacitor
     * clients skip it so field login is not blocked by a web widget.
     */
    public function requiredFor(Request $request): bool
    {
        if (strtolower((string) $request->header('X-Agri-Platform')) === 'native') {
            return false;
        }

        return $this->isEnabled();
    }

    /**
     * Verify a Cloudflare Turnstile token.
     *
     * Production fails closed when the secret is missing. Local/testing
     * skip verification so the app still boots without Turnstile configured.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->isEnabled()) {
            if (app()->environment('production')) {
                Log::error('Turnstile secret is not configured; rejecting login.');

                return false;
            }

            return true;
        }

        if (! filled($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(config('services.turnstile.verify_url'), array_filter([
                    'secret' => config('services.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));

            $payload = $response->json();

            if (! $response->successful() || ! is_array($payload)) {
                Log::warning('Turnstile siteverify request failed.', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            return ($payload['success'] ?? false) === true;
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification error: '.$e->getMessage());

            return false;
        }
    }
}
