<?php

namespace Tests\Unit;

use App\Services\SmsService;
use App\Services\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_override_wins_over_env(): void
    {
        config(['services.sms.provider' => 'iprog']);

        $settings = app(SystemSettingService::class);
        $settings->set(SystemSettingService::SMS_PROVIDER_KEY, 'semaphore');

        $this->assertSame('semaphore', app(SmsService::class)->provider());
    }
}
