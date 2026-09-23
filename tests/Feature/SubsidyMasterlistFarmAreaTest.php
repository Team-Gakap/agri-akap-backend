<?php

namespace Tests\Feature;

use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\PlantingLog;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubsidyMasterlistFarmAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_masterlist_farm_area_uses_registered_plot_not_sum_of_active_plantings(): void
    {
        $admin = User::factory()->admin()->create();
        $farmer = Farmer::factory()->inBarangay('San Fabian')->create([
            'rsbsa_no' => 'IV-02-0423-2026-906',
        ]);

        $plot = FarmPlot::create([
            'id' => (string) Str::uuid(),
            'farmer_id' => $farmer->id,
            'location_brgy' => 'San Fabian',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => 9.0,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'deed.pdf',
            'commodity' => 'Rice',
            'size_ha' => 9.0,
            'farm_type' => 'Irrigated',
        ]);

        // Nine seasonal Active encodings at full plot size must not become 81 ha.
        for ($i = 0; $i < 9; $i++) {
            PlantingLog::create([
                'id' => (string) Str::uuid(),
                'farmer_id' => $farmer->id,
                'farm_plot_id' => $plot->id,
                'technician_id' => $admin->id,
                'crop_type' => 'Rice',
                'variety' => 'NSIC Rc222',
                'area_planted' => 9.0,
                'date_planted' => now()->subMonths(9 - $i)->toDateString(),
                'status' => 'Active',
            ]);
        }

        $program = SubsidyProgram::create([
            'id' => (string) Str::uuid(),
            'program_name' => 'Rice Cash FY2026',
            'target_crop' => 'Rice',
            'max_hectares_limit' => 2,
            'items_per_hectare' => 2500,
            'status' => 'Draft',
            'unit_of_measurement' => 'Cash (PHP)',
            'seed_class' => 'Hybrid',
            'item_type' => 'cash',
            'total_quantity' => 1_000_000,
            'remaining_quantity' => 1_000_000,
            'reorder_level' => 1000,
        ]);

        Sanctum::actingAs($admin);

        $generate = $this->postJson("/api/subsidies/{$program->id}/generate-masterlist");
        $generate->assertOk();
        $this->assertSame(1, (int) $generate->json('data.generated_count'));

        $beneficiary = SubsidyBeneficiary::query()
            ->where('program_id', $program->id)
            ->where('farmer_rsbsa_no', $farmer->rsbsa_no)
            ->first();

        $this->assertNotNull($beneficiary);
        // Cap 2 ha × 2500 = 5000 (not 81 × 2500).
        $this->assertSame(5000, (int) $beneficiary->calculated_allocation);

        $masterlist = $this->getJson("/api/subsidies/{$program->id}/masterlist");
        $masterlist->assertOk();

        $row = collect($masterlist->json('data.masterlist'))
            ->firstWhere('rsbsa_no', $farmer->rsbsa_no);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(9.0, (float) $row['farm_area'], 0.0001);
        $this->assertSame(5000, (int) $row['calculated_allocation']);
    }

    public function test_regenerate_recalculates_pending_allocation_after_area_fix(): void
    {
        $admin = User::factory()->admin()->create();
        $farmer = Farmer::factory()->inBarangay('San Fabian')->create();

        FarmPlot::create([
            'id' => (string) Str::uuid(),
            'farmer_id' => $farmer->id,
            'location_brgy' => 'San Fabian',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => 7.0,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'deed.pdf',
            'commodity' => 'Rice',
            'size_ha' => 7.0,
            'farm_type' => 'Irrigated',
        ]);

        $program = SubsidyProgram::create([
            'id' => (string) Str::uuid(),
            'program_name' => 'Rice Seed FY2026',
            'target_crop' => 'Rice',
            'max_hectares_limit' => 10,
            'items_per_hectare' => 40,
            'status' => 'Draft',
            'unit_of_measurement' => 'kg',
            'total_quantity' => 10000,
            'remaining_quantity' => 10000,
            'reorder_level' => 100,
        ]);

        // Stale inflated Pending allocation (as if generated under the old SUM bug).
        $beneficiary = SubsidyBeneficiary::create([
            'id' => (string) Str::uuid(),
            'program_id' => $program->id,
            'farmer_rsbsa_no' => $farmer->rsbsa_no,
            'calculated_allocation' => 840, // 21 ha × 40
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($admin);

        $generate = $this->postJson("/api/subsidies/{$program->id}/generate-masterlist");
        $generate->assertOk();
        $this->assertSame(0, (int) $generate->json('data.generated_count'));
        $this->assertSame(1, (int) $generate->json('data.updated_count'));

        // 7 ha × 40 = 280 (capped only by max_hectares 10).
        $this->assertSame(280, (int) $beneficiary->fresh()->calculated_allocation);
    }
}
