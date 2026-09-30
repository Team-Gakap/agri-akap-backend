<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAppDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_can_download_the_apk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('apk/AGRI-AKAP-Technician.apk', 'signed-apk-bytes');
        Sanctum::actingAs(User::factory()->technician()->create());

        $this->get('/api/mobile/download-apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('content-disposition', 'attachment; filename=AGRI-AKAP-Technician.apk')
            ->assertStreamedContent('signed-apk-bytes');
    }

    public function test_apk_download_requires_a_technician_or_admin_session(): void
    {
        $this->getJson('/api/mobile/download-apk')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->barangayOfficial()->create());
        $this->getJson('/api/mobile/download-apk')->assertForbidden();
    }

    public function test_missing_apk_returns_not_found(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->technician()->create());

        $this->getJson('/api/mobile/download-apk')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_version_endpoint_returns_configured_release_metadata(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('apk/AGRI-AKAP-Technician.apk', 'signed-apk-bytes');
        config([
            'mobile.version' => '1.0.4',
            'mobile.force_update' => true,
        ]);
        Sanctum::actingAs(User::factory()->technician()->create());

        $this->getJson('/api/mobile/version')
            ->assertOk()
            ->assertJsonPath('latest_version', '1.0.4')
            ->assertJsonPath('force_update', true)
            ->assertJsonPath('apk_available', true)
            ->assertJsonPath('apk_url', route('api.mobile.download-apk'));
    }
}
