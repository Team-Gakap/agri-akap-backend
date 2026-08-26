<?php

namespace Tests\Unit;

use App\Services\TurnstileService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileServiceTest extends TestCase
{
    public function test_skips_verification_when_secret_is_empty_outside_production(): void
    {
        config(['services.turnstile.secret' => '']);

        $this->assertTrue(app(TurnstileService::class)->verify('any-token', '127.0.0.1'));
    }

    public function test_rejects_empty_token_when_enabled(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);

        $this->assertFalse(app(TurnstileService::class)->verify('', '127.0.0.1'));
    }

    public function test_accepts_successful_cloudflare_response(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        $this->assertTrue(app(TurnstileService::class)->verify('ok-token', '127.0.0.1'));
    }

    public function test_native_platform_header_skips_captcha_requirement(): void
    {
        $request = \Illuminate\Http\Request::create('/api/login', 'POST');
        $request->headers->set('X-Agri-Platform', 'native');
        config(['services.turnstile.secret' => 'test-secret']);

        $this->assertFalse(app(TurnstileService::class)->requiredFor($request));
    }

    public function test_rejects_failed_cloudflare_response(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $this->assertFalse(app(TurnstileService::class)->verify('bad-token', '127.0.0.1'));
    }
}
