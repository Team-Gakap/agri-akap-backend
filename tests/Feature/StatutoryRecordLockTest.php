<?php

namespace Tests\Feature;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\PestMonitoring;
use App\Models\SubsidyBeneficiary;
use App\Models\SubsidyProgram;
use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatutoryRecordLockTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlotContext(?User $encoder = null): array
    {
        $encoder ??= User::factory()->admin()->create();
        $farmer = Farmer::factory()->inBarangay('San Fabian')->create();
        $plot = FarmPlot::create([
            'id' => (string) Str::uuid(),
            'farmer_id' => $farmer->id,
            'location_brgy' => 'San Fabian',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => 1.5,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'deed.pdf',
            'commodity' => 'Rice',
            'size_ha' => 1.5,
            'farm_type' => 'Irrigated',
        ]);

        return compact('encoder', 'farmer', 'plot');
    }

    private function makePendingDamage(Farmer $farmer, FarmPlot $plot, User $encoder): DamageAssessment
    {
        return DamageAssessment::create([
            'id' => (string) Str::uuid(),
            'farm_plot_id' => $plot->id,
            'farmer_id' => $farmer->id,
            'technician_id' => $encoder->id,
            'calamity_type' => 'Typhoon',
            'calamity_name' => 'Typhoon',
            'date_of_calamity' => now()->toDateString(),
            'damage_percentage' => 25,
            'area_destroyed_ha' => 0.3,
            'area_planted_ha' => 1.0,
            'status' => 'Pending',
        ]);
    }

    private function makeVerifiedDamage(Farmer $farmer, FarmPlot $plot, User $encoder): DamageAssessment
    {
        $row = $this->makePendingDamage($farmer, $plot, $encoder);
        $row->update([
            'status' => 'Verified',
            'latitude' => 16.7,
            'longitude' => 121.6,
            'photo_evidence_path' => 'assessments/evidence.jpg',
            'verified_at' => now(),
            'verified_by' => $encoder->id,
        ]);

        return $row->fresh();
    }

    private function makePendingPest(Farmer $farmer, FarmPlot $plot): PestMonitoring
    {
        return PestMonitoring::create([
            'id' => (string) Str::uuid(),
            'farmer_id' => $farmer->id,
            'farm_plot_id' => $plot->id,
            'crop' => 'Rice',
            'pest_name' => 'Stem Borer',
            'area_planted' => 1.0,
            'days_after_planting' => 30,
            'area_damage_pct' => 10,
            'date_of_inspection' => now()->toDateString(),
            'latitude' => null,
            'photo_path' => null,
        ]);
    }

    private function makeValidatedPest(Farmer $farmer, FarmPlot $plot, User $tech): PestMonitoring
    {
        $row = $this->makePendingPest($farmer, $plot);
        $row->update([
            'latitude' => 16.7,
            'longitude' => 121.6,
            'photo_path' => 'pest-monitoring/evidence.jpg',
            'technician_id' => $tech->id,
        ]);

        return $row->fresh();
    }

    private function makeClaimedSubsidy(Farmer $farmer, User $admin): array
    {
        $program = SubsidyProgram::create([
            'id' => (string) Str::uuid(),
            'program_name' => 'Rice Seed FY2026',
            'target_crop' => 'Rice',
            'max_hectares_limit' => 2,
            'items_per_hectare' => 40,
            'status' => 'Active',
            'unit_of_measurement' => 'kg',
            'total_quantity' => 1000,
            'remaining_quantity' => 900,
            'reorder_level' => 100,
        ]);

        $beneficiary = SubsidyBeneficiary::create([
            'id' => (string) Str::uuid(),
            'program_id' => $program->id,
            'farmer_rsbsa_no' => $farmer->rsbsa_no,
            'calculated_allocation' => 40,
            'status' => 'Claimed',
            'claimed_at' => now(),
            'claimed_by' => $admin->id,
            'photo_proof_path' => 'subsidy/claim.jpg',
        ]);

        return compact('program', 'beneficiary');
    }

    public function test_pending_damage_can_be_patched_and_soft_deleted(): void
    {
        ['encoder' => $admin, 'farmer' => $farmer, 'plot' => $plot] = $this->seedPlotContext();
        $row = $this->makePendingDamage($farmer, $plot, $admin);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/damage-assessments/{$row->id}", [
            'damage_percentage' => 40,
        ])->assertOk();

        $this->assertSame(40.0, (float) $row->fresh()->damage_percentage);

        $this->deleteJson("/api/damage-assessments/{$row->id}")->assertOk();
        $this->assertNotNull($row->fresh()->deleted_at);
        $this->assertNull(DamageAssessment::find($row->id));
    }

    public function test_verified_damage_update_is_forbidden_for_all_roles(): void
    {
        ['encoder' => $admin, 'farmer' => $farmer, 'plot' => $plot] = $this->seedPlotContext();
        $row = $this->makeVerifiedDamage($farmer, $plot, $admin);
        $brgy = User::factory()->barangayOfficial('San Fabian')->create();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/damage-assessments/{$row->id}", [
            'damage_percentage' => 55,
        ])->assertForbidden();

        Sanctum::actingAs($brgy);
        $this->patchJson("/api/damage-assessments/{$row->id}", [
            'damage_percentage' => 55,
        ])->assertForbidden();
    }

    public function test_verified_damage_void_requires_admin_and_remarks_with_diffs(): void
    {
        ['encoder' => $admin, 'farmer' => $farmer, 'plot' => $plot] = $this->seedPlotContext();
        $row = $this->makeVerifiedDamage($farmer, $plot, $admin);
        $brgy = User::factory()->barangayOfficial('San Fabian')->create();

        Sanctum::actingAs($brgy);
        $this->deleteJson("/api/damage-assessments/{$row->id}")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/damage-assessments/{$row->id}")->assertStatus(422);

        $this->deleteJson("/api/damage-assessments/{$row->id}", [
            'audit_remarks' => 'Duplicate verified entry after ocular inspection.',
        ])->assertOk();

        $this->assertNotNull($row->fresh()->deleted_at);

        $log = SystemAuditLog::query()->where('action', 'damage_assessment.deleted')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Duplicate verified entry after ocular inspection.', $log->remarks);
        $this->assertSame('Verified', $log->before_state['status'] ?? null);
        $this->assertArrayHasKey('deleted_at', $log->after_state ?? []);
    }

    public function test_pending_pest_can_be_patched_and_soft_deleted(): void
    {
        ['encoder' => $admin, 'farmer' => $farmer, 'plot' => $plot] = $this->seedPlotContext();
        $row = $this->makePendingPest($farmer, $plot);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/pest-monitoring/{$row->id}", [
            'area_damage_pct' => 22,
        ])->assertOk();

        $this->assertSame(22.0, (float) $row->fresh()->area_damage_pct);

        $this->deleteJson("/api/pest-monitoring/{$row->id}")->assertOk();
        $this->assertNotNull($row->fresh()->deleted_at);
        $this->assertNull(PestMonitoring::find($row->id));
    }

    public function test_validated_pest_update_is_forbidden_for_all_roles(): void
    {
        ['encoder' => $admin, 'farmer' => $farmer, 'plot' => $plot] = $this->seedPlotContext();
        $row = $this->makeValidatedPest($farmer, $plot, $admin);
        $brgy = User::factory()->barangayOfficial('San Fabian')->create();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/pest-monitoring/{$row->id}", [
            'area_damage_pct' => 50,
        ])->assertForbidden();

        Sanctum::actingAs($brgy);
        $this->patchJson("/api/pest-monitoring/{$row->id}", [
            'area_damage_pct' => 50,
        ])->assertForbidden();
    }

    public function test_validated_pest_void_requires_admin_and_remarks_with_diffs(): void
    {
        ['encoder' => $admin, 'farmer' => $farmer, 'plot' => $plot] = $this->seedPlotContext();
        $row = $this->makeValidatedPest($farmer, $plot, $admin);
        $brgy = User::factory()->barangayOfficial('San Fabian')->create();

        Sanctum::actingAs($brgy);
        $this->deleteJson("/api/pest-monitoring/{$row->id}")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/pest-monitoring/{$row->id}")->assertStatus(422);

        $this->deleteJson("/api/pest-monitoring/{$row->id}", [
            'audit_remarks' => 'GPS and photo attached to wrong plot; voiding for re-encode.',
        ])->assertOk();

        $this->assertNotNull($row->fresh()->deleted_at);

        $log = SystemAuditLog::query()->where('action', 'pest_monitoring.deleted')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('GPS and photo attached to wrong plot; voiding for re-encode.', $log->remarks);
        $this->assertSame('Stem Borer', $log->before_state['pest_name'] ?? null);
        $this->assertArrayHasKey('deleted_at', $log->after_state ?? []);
    }

    public function test_claimed_subsidy_cannot_be_edited_and_void_requires_remarks_with_diffs(): void
    {
        $admin = User::factory()->admin()->create();
        $farmer = Farmer::factory()->inBarangay('San Fabian')->create();
        ['program' => $program, 'beneficiary' => $beneficiary] = $this->makeClaimedSubsidy($farmer, $admin);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/subsidies/beneficiaries/{$beneficiary->id}", [
            'claimed_at' => now()->subDay()->toDateString(),
        ])->assertForbidden();

        $this->deleteJson("/api/subsidies/beneficiaries/{$beneficiary->id}")->assertStatus(422);

        $this->deleteJson("/api/subsidies/beneficiaries/{$beneficiary->id}", [
            'audit_remarks' => 'Wrong farmer claimed; voiding to restock and re-issue.',
        ])->assertOk();

        $beneficiary->refresh();
        $program->refresh();

        $this->assertSame('Pending', $beneficiary->status);
        $this->assertNull($beneficiary->claimed_at);
        $this->assertNull($beneficiary->claimed_by);
        $this->assertSame(940.0, (float) $program->remaining_quantity);

        $log = SystemAuditLog::query()->where('action', 'subsidy_beneficiary.voided')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Wrong farmer claimed; voiding to restock and re-issue.', $log->remarks);
        $this->assertSame('Claimed', $log->before_state['status'] ?? null);
        $this->assertSame('Pending', $log->after_state['status'] ?? null);
        $this->assertSame(40, (int) ($log->after_state['restocked_primary'] ?? 0));
    }
}
