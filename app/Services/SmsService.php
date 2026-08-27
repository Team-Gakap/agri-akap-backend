<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic SMS gateway. Switches between IPROG and Semaphore based on
 * config('services.sms.provider'). All methods degrade gracefully and never
 * throw so callers (e.g. enrollment) are never blocked by SMS failures.
 */
class SmsService
{
    /**
     * Whether a real gateway (or a local/testing mock) can deliver OTP SMS.
     */
    public function isConfigured(): bool
    {
        $provider = config('services.sms.provider', 'iprog');

        if ($provider === 'semaphore') {
            return filled(config('services.sms.semaphore.key'))
                || app()->environment('local', 'testing');
        }

        if (filled(config('services.sms.iprog.token'))) {
            return true;
        }

        return app()->environment('testing');
    }

    /**
     * Send one message to a single recipient.
     */
    public function send(string $number, string $message): array
    {
        return $this->sendBulk([$number], $message);
    }

    /**
     * Send one message to many recipients.
     *
     * @param  array<int,string>  $numbers
     * @return array{success:bool, provider:string, recipients:int, raw:mixed}
     */
    public function sendBulk(array $numbers, string $message): array
    {
        $provider = config('services.sms.provider', 'iprog');

        $numbers = array_values(array_filter(array_map('trim', $numbers)));
        $csv = implode(',', $numbers);

        if ($csv === '') {
            return $this->result(false, $provider, 0, 'No recipients provided.');
        }

        try {
            return $provider === 'semaphore'
                ? $this->sendViaSemaphore($csv, $message, count($numbers))
                : $this->sendViaIprog($csv, $message, count($numbers));
        } catch (\Throwable $e) {
            Log::error("SMS ({$provider}) dispatch failed: " . $e->getMessage());
            return $this->result(false, $provider, count($numbers), $e->getMessage());
        }
    }

    /**
     * IPROG bulk endpoint: api_token + phone_number (CSV) + message.
     */
    protected function sendViaIprog(string $csv, string $message, int $count): array
    {
        $config = config('services.sms.iprog');
        $token = $config['token'] ?? '';

        if (app()->environment('testing') || ($token === '' && app()->environment('local'))) {
            Log::info('IPROG SMS mocked', ['recipients' => $count, 'message' => $message]);

            return $this->result(true, 'iprog-mock', $count, ['mocked' => true, 'recipients' => $count]);
        }

        $url = rtrim($config['base_url'] ?? 'https://sms.iprogtech.com', '/')
            . '/api/v1/sms_messages/send_bulk';

        $response = Http::asForm()->timeout(10)->post($url, [
            'api_token' => $config['token'] ?? '',
            'phone_number' => $csv,
            'message' => $message,
        ]);

        return $this->result($response->successful(), 'iprog', $count, $response->json() ?? $response->body());
    }

    /**
     * Semaphore v4 endpoint: apikey + number (CSV) + message + sendername.
     */
    protected function sendViaSemaphore(string $csv, string $message, int $count): array
    {
        $config = config('services.sms.semaphore');
        $apiKey = $config['key'] ?? '';

        // Avoid accidental SMS charges when the key is missing or in local/testing.
        if ($apiKey === '' || app()->environment('local', 'testing')) {
            Log::info('Semaphore SMS mocked', ['recipients' => $count, 'message' => $message]);

            return $this->result(true, 'semaphore-mock', $count, ['mocked' => true, 'recipients' => $count]);
        }

        $response = Http::asForm()->timeout(10)->post('https://api.semaphore.co/api/v4/messages', [
            'apikey' => $apiKey,
            'number' => $csv,
            'message' => $message,
            'sendername' => $config['sender'] ?? 'MAO-ECHAGUE',
        ]);

        return $this->result($response->successful(), 'semaphore', $count, $response->json() ?? $response->body());
    }

    /**
     * Normalize a provider response into a consistent shape.
     */
    protected function result(bool $success, string $provider, int $recipients, mixed $raw): array
    {
        return [
            'success' => $success,
            'provider' => $provider,
            'recipients' => $recipients,
            'raw' => $raw,
        ];
    }
}
