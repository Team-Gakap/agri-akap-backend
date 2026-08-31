<?php

namespace Tests\Feature;

use App\Models\Farmer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmerPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG so DecodesBase64Image can persist a real file. */
    private const TINY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_barangay_official_can_upload_photo_for_own_barangay_farmer(): void
    {
        Storage::fake('public');

        $official = User::factory()->barangayOfficial('San Fabian')->create();
        $farmer = Farmer::factory()->inBarangay('San Fabian')->create();

        Sanctum::actingAs($official);

        $this->postJson("/api/farmers/{$farmer->id}/photo", [
            'photo_base64' => self::TINY_PNG,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $farmer->refresh();
        $this->assertNotEmpty($farmer->photo_path);
        Storage::disk('public')->assertExists($farmer->photo_path);
    }

    public function test_barangay_official_cannot_upload_photo_for_another_barangay(): void
    {
        Storage::fake('public');

        $official = User::factory()->barangayOfficial('San Fabian')->create();
        $farmer = Farmer::factory()->inBarangay('Ipil')->create();

        Sanctum::actingAs($official);

        $this->postJson("/api/farmers/{$farmer->id}/photo", [
            'photo_base64' => self::TINY_PNG,
        ])
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertNull($farmer->fresh()->photo_path);
    }

    public function test_barangay_official_can_show_own_barangay_farmer(): void
    {
        $official = User::factory()->barangayOfficial('San Fabian')->create();
        $farmer = Farmer::factory()->inBarangay('San Fabian')->create();

        Sanctum::actingAs($official);

        $this->getJson("/api/farmers/{$farmer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $farmer->id);
    }

    public function test_has_photo_filter_returns_only_farmers_missing_a_portrait(): void
    {
        $official = User::factory()->barangayOfficial('San Fabian')->create();
        Farmer::factory()->inBarangay('San Fabian')->create();
        Farmer::factory()->inBarangay('San Fabian')->withPhoto()->create();

        Sanctum::actingAs($official);

        $this->getJson('/api/farmers?has_photo=0')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/farmers?has_photo=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_barangay_official_cannot_show_farmer_from_another_barangay(): void
    {
        $official = User::factory()->barangayOfficial('San Fabian')->create();
        $farmer = Farmer::factory()->inBarangay('Ipil')->create();

        Sanctum::actingAs($official);

        $this->getJson("/api/farmers/{$farmer->id}")
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
    }

    public function test_technician_cannot_upload_farmer_photo(): void
    {
        Storage::fake('public');

        $tech = User::factory()->technician()->create();
        $farmer = Farmer::factory()->create();

        Sanctum::actingAs($tech);

        $this->postJson("/api/farmers/{$farmer->id}/photo", [
            'photo_base64' => self::TINY_PNG,
        ])->assertForbidden();
    }

    public function test_admin_can_upload_farmer_photo(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $farmer = Farmer::factory()->create();

        Sanctum::actingAs($admin);

        $this->postJson("/api/farmers/{$farmer->id}/photo", [
            'photo_base64' => self::TINY_PNG,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotEmpty($farmer->fresh()->photo_path);
    }
}
