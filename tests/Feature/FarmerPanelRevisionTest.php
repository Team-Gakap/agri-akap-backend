<?php

namespace Tests\Feature;

use App\Models\Farmer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmerPanelRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_saves_unlimited_associations_and_other_plot_answers(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/farmers', $this->payload('TX-PANEL-001'))
            ->assertCreated()
            ->assertJsonPath('data.associations.3', 'Fourth Cooperative');

        $farmer = Farmer::where('transaction_code', 'TX-PANEL-001')->firstOrFail();
        $plot = $farmer->farmPlots()->firstOrFail();

        $this->assertSame(['One Cooperative', 'Two Cooperative', 'Three Cooperative', 'Fourth Cooperative'], $farmer->associations);
        $this->assertSame('One Cooperative', $farmer->association_1);
        $this->assertSame('Other local tenure', $plot->ownership_type_other);
        $this->assertSame('Barangay land-use certificate', $plot->proof_of_ownership_other);
        $this->assertSame('Greenhouse', $plot->farm_type_other);
    }

    public function test_tenant_plot_does_not_require_land_owner_rsbsa_number_and_update_keeps_associations(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $farmer = Farmer::factory()->create(['associations' => ['Legacy One', 'Legacy Two']]);
        $payload = $this->payload($farmer->transaction_code);
        $payload['rsbsa_no'] = $farmer->rsbsa_no;
        $payload['plots'][0]['ownership_type'] = 'Tenant';
        $payload['plots'][0]['ownership_type_other'] = null;
        $payload['plots'][0]['land_owner_first_name'] = 'Ana';
        $payload['plots'][0]['land_owner_middle_name'] = 'Diaz';
        $payload['plots'][0]['land_owner_surname'] = 'Santos';
        $payload['plots'][0]['land_owner_ext_name'] = 'Jr.';
        $payload['plots'][0]['proof_of_ownership_document'] = 'Other';
        $payload['plots'][0]['proof_of_ownership_other'] = 'Tenant certificate';

        $this->patchJson("/api/farmers/{$farmer->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.associations.3', 'Fourth Cooperative');
    }

    public function test_other_values_require_their_specification(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $payload = $this->payload('TX-PANEL-003');
        $payload['other_livelihood_type'] = 'Other';
        $payload['other_livelihood_detail'] = '';
        $payload['plots'][0]['proof_of_ownership_other'] = '';

        $this->postJson('/api/farmers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'other_livelihood_detail',
                'plots.0.proof_of_ownership_other',
            ]);
    }

    private function payload(string $transactionCode): array
    {
        return [
            'transaction_code' => $transactionCode,
            'surname' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Reyes',
            'sex' => 'Male', 'birthdate' => '1980-01-01',
            'permanent_brgy' => 'San Fabian', 'permanent_city' => 'Echague',
            'permanent_province' => 'Isabela', 'permanent_region' => 'Region II',
            'mobile_number' => '09171234567',
            'mothers_maiden_first_name' => 'Maria', 'mothers_maiden_surname' => 'Reyes',
            'civil_status' => 'Single', 'highest_education' => 'College',
            'livelihood_type' => 'Farmer', 'other_livelihood_type' => 'Other',
            'other_livelihood_detail' => 'Beekeeper',
            'associations' => ['One Cooperative', 'Two Cooperative', 'Three Cooperative', 'Fourth Cooperative'],
            'plots' => [[
                'location_brgy' => 'San Fabian', 'location_city' => 'Echague', 'location_province' => 'Isabela',
                'total_parcel_area_ha' => 1.25, 'size_ha' => 1.25,
                'ownership_type' => 'Others', 'ownership_type_other' => 'Other local tenure',
                'proof_of_ownership_document' => 'Other', 'proof_of_ownership_other' => 'Barangay land-use certificate',
                'commodity' => 'Rice', 'farm_type' => 'Other', 'farm_type_other' => 'Greenhouse',
            ]],
        ];
    }
}
