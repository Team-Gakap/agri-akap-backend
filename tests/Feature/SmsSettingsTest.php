<?php

namespace Tests\Feature;

use App\Http\Controllers\DistributionController;
use App\Http\Controllers\FarmerController;
use App\Http\Controllers\SyncController;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Services\SmsService;
use App\Services\SystemSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_read_or_update_sms_settings(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/system/sms-settings')->assertForbidden();
        $this->patchJson('/api/system/sms-settings', ['provider' => 'semaphore'])->assertForbidden();
    }

    public function test_sms_provider_falls_back_to_env_when_unset(): void
    {
        config(['services.sms.provider' => 'semaphore']);

        $this->assertSame('semaphore', app(SmsService::class)->provider());
        $this->assertSame('env', app(SystemSettingService::class)->smsProviderSource());
    }

    public function test_super_admin_can_switch_provider_and_sms_service_reads_it(): void
    {
        config(['services.sms.provider' => 'iprog']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/system/sms-settings')
            ->assertOk()
            ->assertJsonPath('data.provider', 'iprog')
            ->assertJsonPath('data.source', 'env');

        $this->patchJson('/api/system/sms-settings', ['provider' => 'semaphore'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'semaphore')
            ->assertJsonPath('data.source', 'database');

        $this->assertSame('semaphore', app(SmsService::class)->provider());
        $this->assertSame('database', app(SystemSettingService::class)->smsProviderSource());
        $this->assertSame(1, SystemAuditLog::query()->where('action', 'sms.provider.updated')->count());
    }

    public function test_production_refuses_provider_without_credentials(): void
    {
        $this->app['env'] = 'production';
        config([
            'services.sms.iprog.token' => '',
            'services.sms.semaphore.key' => '',
        ]);

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->patchJson('/api/system/sms-settings', ['provider' => 'semaphore'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Semaphore API key is not configured on the server.');
    }

    public function test_weather_alert_command_is_removed(): void
    {
        $this->assertArrayNotHasKey('weather:alert', Artisan::all());
    }

    public function test_automatic_sms_receipt_methods_are_removed(): void
    {
        $this->assertFalse(method_exists(FarmerController::class, 'sendEnrollmentReceipt'));
        $this->assertFalse(method_exists(SyncController::class, 'sendGeoreferencingReceipt'));
        $this->assertFalse(method_exists(DistributionController::class, 'sendClaimReceipt'));
    }

    public function test_farmer_enrollment_does_not_dispatch_sms(): void
    {
        Http::fake();

        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('send')->never();
            $mock->shouldReceive('sendBulk')->never();
        });

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/farmers', $this->enrollmentPayload())
            ->assertCreated();

        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function enrollmentPayload(): array
    {
        return [
            'transaction_code' => 'TXN-SMS-TEST-001',
            'surname' => 'Dela Cruz',
            'first_name' => 'Juan',
            'sex' => 'Male',
            'birthdate' => '1980-01-15',
            'mobile_number' => '09171234567',
            'mothers_maiden_first_name' => 'Maria',
            'mothers_maiden_surname' => 'Santos',
            'civil_status' => 'Single',
            'highest_education' => 'College',
            'permanent_brgy' => 'San Fabian',
            'permanent_city' => 'Echague',
            'permanent_province' => 'Isabela',
            'permanent_region' => '02',
            'livelihood_type' => 'Farmer',
            'plots' => [[
                'location_brgy' => 'San Fabian',
                'location_city' => 'Echague',
                'location_province' => 'Isabela',
                'total_parcel_area_ha' => 1,
                'ownership_type' => 'Registered Owner',
                'proof_of_ownership_document' => 'Title',
                'commodity' => 'Rice',
                'size_ha' => 1,
                'farm_type' => 'Irrigated',
            ]],
        ];
    }
}
