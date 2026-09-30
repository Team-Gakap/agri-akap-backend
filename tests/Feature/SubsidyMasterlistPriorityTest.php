<?php

namespace Tests\Feature;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubsidyMasterlistPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_masterlist_ranks_priority_and_waitlists_past_stock_cutoff(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $senior = $this->farmer('Abe', '1950-01-01', false);
        $pwd = $this->farmer('Aaron', '1990-06-01', true);
        $damaged = $this->farmer('Baker', '1992-03-01', false);
        $small = $this->farmer('Cruz', '1994-04-01', false);
        $large = $this->farmer('Diaz', '1991-08-01', false);

        $this->plot($senior, 0.5);
        $this->plot($pwd, 0.5);
        $damagedPlot = $this->plot($damaged, 3.0);
        $this->plot($small, 1.0);
        $this->plot($large, 4.0);

        DamageAssessment::create([
            'id' => (string) Str::uuid(),
            'farm_plot_id' => $damagedPlot->id,
            'farmer_id' => $damaged->id,
            'technician_id' => $admin->id,
            'calamity_name' => 'Typhoon',
            'calamity_type' => 'Typhoon',
            'date_of_calamity' => now()->subMonths(2)->toDateString(),
            'damage_percentage' => 80,
            'status' => 'Approved',
        ]);

        $program = $this->program(6);

        $generate = $this->postJson("/api/subsidies/{$program->id}/generate-masterlist");
        $generate->assertOk();

        $this->assertSame('Pending', $this->statusOf($program, $senior));
        $this->assertSame(1, $this->tierOf($program, $senior));
        $this->assertSame('Pending', $this->statusOf($program, $pwd));
        $this->assertSame(1, $this->tierOf($program, $pwd));

        // 2 bags would still fit in the leftover buffer, but the cutoff is hard
        // once a higher-ranked farmer no longer fits.
        $this->assertSame('Waitlisted', $this->statusOf($program, $damaged));
        $this->assertSame(2, $this->tierOf($program, $damaged));
        $this->assertSame('Waitlisted', $this->statusOf($program, $small));
        $this->assertSame(3, $this->tierOf($program, $small));
        $this->assertSame('Waitlisted', $this->statusOf($program, $large));
        $this->assertNull($this->tierOf($program, $large));

        $program->update([
            'total_quantity' => 40,
            'remaining_quantity' => 40,
        ]);

        $again = $this->postJson("/api/subsidies/{$program->id}/generate-masterlist");
        $again->assertOk();
        $this->assertGreaterThan(0, (int) $again->json('data.updated_count'));

        foreach ([$senior, $pwd, $damaged, $small, $large] as $farmer) {
            $this->assertSame('Pending', $this->statusOf($program, $farmer));
        }
    }

    public function test_auto_masterlist_skips_farmers_already_on_another_active_program_for_the_same_crop(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $already = $this->farmer('Already', '1990-01-01', false);
        $fresh = $this->farmer('Fresh', '1991-01-01', false);
        $this->plot($already, 1.0);
        $this->plot($fresh, 1.0);

        $first = $this->program(100, 'Rice Seed A');
        SubsidyBeneficiary::create([
            'id' => (string) Str::uuid(),
            'program_id' => $first->id,
            'farmer_rsbsa_no' => $already->rsbsa_no,
            'calculated_allocation' => 2,
            'status' => 'Pending',
            'selection_mode' => 'auto',
        ]);

        $second = $this->program(100, 'Rice Seed B');
        $generate = $this->postJson("/api/subsidies/{$second->id}/generate-masterlist");
        $generate->assertOk();

        $this->assertNull(SubsidyBeneficiary::query()
            ->where('program_id', $second->id)
            ->where('farmer_rsbsa_no', $already->rsbsa_no)
            ->first());

        $this->assertNotNull(SubsidyBeneficiary::query()
            ->where('program_id', $second->id)
            ->where('farmer_rsbsa_no', $fresh->rsbsa_no)
            ->first());
    }

    private function farmer(string $surname, string $birthdate, bool $isPwd): Farmer
    {
        return Farmer::factory()->inBarangay('San Fabian')->create([
            'surname' => $surname,
            'birthdate' => $birthdate,
            'is_pwd' => $isPwd,
        ]);
    }

    private function plot(Farmer $farmer, float $hectares): FarmPlot
    {
        return FarmPlot::create([
            'id' => (string) Str::uuid(),
            'farmer_id' => $farmer->id,
            'location_brgy' => 'San Fabian',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => $hectares,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'deed.pdf',
            'commodity' => 'Rice',
            'size_ha' => $hectares,
            'farm_type' => 'Irrigated',
        ]);
    }

    private function program(float $stock, string $name = 'Inbred Rice Certified Seeds'): SubsidyProgram
    {
        return SubsidyProgram::create([
            'id' => (string) Str::uuid(),
            'program_name' => $name,
            'target_crop' => 'Rice',
            'max_hectares_limit' => 10,
            'items_per_hectare' => 2,
            'status' => 'Draft',
            'unit_of_measurement' => 'bags',
            'seed_class' => 'Inbred',
            'item_type' => 'seed',
            'total_quantity' => $stock,
            'remaining_quantity' => $stock,
        ]);
    }

    private function statusOf(SubsidyProgram $program, Farmer $farmer): ?string
    {
        return SubsidyBeneficiary::query()
            ->where('program_id', $program->id)
            ->where('farmer_rsbsa_no', $farmer->rsbsa_no)
            ->value('status');
    }

    private function tierOf(SubsidyProgram $program, Farmer $farmer): ?int
    {
        $tier = SubsidyBeneficiary::query()
            ->where('program_id', $program->id)
            ->where('farmer_rsbsa_no', $farmer->rsbsa_no)
            ->value('priority_tier');

        return $tier === null ? null : (int) $tier;
    }
}
