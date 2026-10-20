<?php

namespace Tests\Feature;

use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\HarvestLog;
use App\Models\SubsidyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaReportEncodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_encode_search_uses_farm_address_and_crop_and_claims_the_imported_plot(): void
    {
        $admin = User::factory()->admin()->create();
        $home = $this->farmer('San Fabian', 'Angoluan', 'Rice/Palay', 1.25);
        $otherCrop = $this->farmer('San Fabian', 'San Fabian', 'Corn', 0.8);
        $unspecified = $this->farmer('San Fabian', 'San Fabian', 'Unspecified', 0.4);
        $farmOnly = $this->farmer('Angoluan', 'San Fabian', 'Rice/Palay', 1.0);
        $riceVariant = $this->farmer('Diasan', 'San Fabian', 'RiceWFallow', 0.6);
        $unclassified = $this->farmer('Diasan', 'Diasan', 'Vegetables', 0.3);

        Sanctum::actingAs($admin);

        $listed = $this->getJson('/api/farmers?barangay=San Fabian&commodity=Rice&per_page=50');
        $listed->assertOk();
        $ids = collect($listed->json('data.data'))->pluck('id');

        $this->assertFalse($ids->contains($home->id));
        $this->assertTrue($ids->contains($unspecified->id));
        $this->assertFalse($ids->contains($otherCrop->id));
        $this->assertTrue($ids->contains($farmOnly->id));
        $this->assertTrue($ids->contains($riceVariant->id));
        $this->assertFalse($ids->contains($unclassified->id));

        $review = $this->getJson('/api/farmers?commodity=unclassified&per_page=50');
        $review->assertOk();
        $reviewIds = collect($review->json('data.data'))->pluck('id');
        $this->assertTrue($reviewIds->contains($unclassified->id));
        $this->assertFalse($reviewIds->contains($otherCrop->id));
        $this->assertFalse($reviewIds->contains($farmOnly->id));

        $plotId = $home->farmPlots()->value('id');
        $payload = [
            'farmer_id' => $home->id,
            'farm_plot_id' => $plotId,
            'barangay_name' => 'Angoluan',
            'crop_type' => 'Rice',
            'variety' => 'NSIC Rc222',
            'farm_location' => 'Angoluan',
        ];

        $planting = $this->postJson('/api/planting-logs', $payload + [
            'area_planted' => 1.25,
            'date_planted' => '2026-06-01',
        ]);
        $planting->assertCreated();
        $this->assertSame($plotId, $planting->json('data.farm_plot_id'));
        $this->assertSame(1, $home->farmPlots()->count());

        $harvest = $this->postJson('/api/harvest-logs', $payload + [
            'area_harvested' => 1.25,
            'total_yield' => 800,
            'yield_unit' => 'Metric Tons',
            'date_harvested' => '2026-09-01',
        ]);
        $harvest->assertCreated();
        $this->assertSame('Kilograms', $harvest->json('data.yield_unit'));
        $this->assertSame('Kilograms', HarvestLog::query()->find($harvest->json('data.id'))->yield_unit);

        $pest = $this->postJson('/api/pest-monitoring', [
            'farmer_id' => $home->id,
            'farm_plot_id' => $plotId,
            'barangay_name' => 'Angoluan',
            'crop' => 'Rice',
            'area_planted' => 1.25,
            'days_after_planting' => 30,
            'area_damage_pct' => 10,
            'damage_by' => 'Stem borer',
            'date_of_inspection' => '2026-07-01',
        ]);
        $pest->assertCreated();
        $this->assertSame($plotId, $pest->json('data.farm_plot_id'));

        $damage = $this->postJson('/api/damage-assessments', [
            'farmer_id' => $home->id,
            'farm_plot_id' => $plotId,
            'barangay_name' => 'Angoluan',
            'crop_type' => 'Rice',
            'calamity_type' => 'Typhoon',
            'date_of_calamity' => '2026-08-01',
            'damage_percentage' => 20,
            'area_planted_ha' => 1.25,
        ]);
        $damage->assertCreated();
        $this->assertSame($plotId, $damage->json('data.farm_plot_id'));
        $this->assertSame(1, $home->farmPlots()->count());
    }

    public function test_active_program_dashboard_matches_the_target_list(): void
    {
        $admin = User::factory()->admin()->create();
        $rice = $this->farmer('San Fabian', 'Angoluan', 'Rice/Palay', 1.5);
        $corn = $this->farmer('Angoluan', 'Angoluan', 'Corn', 2.0);

        $program = SubsidyProgram::create([
            'id' => (string) Str::uuid(),
            'program_name' => 'Rice Seed FY2026',
            'target_crop' => 'Rice',
            'max_hectares_limit' => 3,
            'items_per_hectare' => 2,
            'status' => 'Active',
            'unit_of_measurement' => 'Bags',
            'seed_class' => 'Hybrid',
            'item_type' => 'seed',
            'total_quantity' => 1000,
            'remaining_quantity' => 1000,
            'reorder_level' => 10,
        ]);

        Sanctum::actingAs($admin);

        $target = $this->getJson("/api/subsidies/{$program->id}/target-beneficiaries?per_page=50");
        $target->assertOk();
        $eligible = (int) $target->json('data.meta.total');
        $claimed = (int) $target->json('data.meta.claimed');
        $this->assertGreaterThan(0, $eligible);

        $beneficiaryIds = collect($target->json('data.beneficiaries'))->pluck('farmer_id');
        $this->assertTrue($beneficiaryIds->contains($rice->id));
        $this->assertFalse($beneficiaryIds->contains($corn->id));
        $homes = collect($target->json('data.beneficiaries'))->pluck('barangay');
        $this->assertTrue($homes->contains('Angoluan'));
        $this->assertFalse($homes->contains('San Fabian'));

        $overview = $this->getJson('/api/dashboard/overview');
        $overview->assertOk();
        $this->assertSame($claimed, (int) $overview->json('data.descriptive.subsidy_beneficiaries_claimed'));
        $this->assertSame($eligible, (int) $overview->json('data.descriptive.subsidy_beneficiaries_enrolled'));
    }

    private function farmer(string $home, string $farm, string $commodity, float $hectares): Farmer
    {
        $farmer = Farmer::factory()->create([
            'permanent_brgy' => $home,
            'farm_brgy' => $farm,
            'total_farm_area_ha' => $commodity === 'Rice/Palay' ? $hectares : 0,
        ]);

        FarmPlot::create([
            'id' => (string) Str::uuid(),
            'farmer_id' => $farmer->id,
            'location_brgy' => $farm,
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => $hectares,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'deed.pdf',
            'commodity' => $commodity,
            'size_ha' => $hectares,
            'farm_type' => 'Irrigated',
        ]);

        return $farmer;
    }
}
