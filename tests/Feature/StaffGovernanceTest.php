<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_admin_gated_routes(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/staff')->assertOk();
        $this->getJson('/api/system/audit-logs')->assertOk();
    }

    public function test_admin_can_read_system_audit_logs(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/system/audit-logs')->assertOk();
    }

    public function test_technician_cannot_read_system_audit_logs(): void
    {
        Sanctum::actingAs(User::factory()->technician()->create());

        $this->getJson('/api/system/audit-logs')->assertForbidden();
    }

    public function test_admin_can_create_technician_but_not_admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $created = $this->postJson('/api/staff', [
            'name' => 'Field Tech',
            'email' => 'newtech@mao.com',
            'role' => 'technician',
        ])->assertCreated();

        $this->assertSame(User::TEMPORARY_PASSWORD, $created->json('data.temporary_password'));
        $this->assertTrue($created->json('data.user.must_change_password'));
        $this->assertTrue(Hash::check(
            User::TEMPORARY_PASSWORD,
            User::query()->where('email', 'newtech@mao.com')->firstOrFail()->password,
        ));

        $this->postJson('/api/staff', [
            'name' => 'Other Admin',
            'email' => 'otheradmin@mao.com',
            'role' => 'admin',
        ])->assertUnprocessable();
    }

    public function test_super_admin_can_create_admin_but_not_another_super_admin(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/staff', [
            'name' => 'MAO Admin',
            'email' => 'newadmin@mao.com',
            'role' => 'admin',
        ])->assertCreated();

        $this->postJson('/api/staff', [
            'name' => 'Root Two',
            'email' => 'root2@mao.com',
            'role' => 'super_admin',
        ])->assertUnprocessable();
    }

    public function test_reset_password_revokes_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->technician()->create();
        $tech->createToken('phpunit');
        $this->assertSame(1, $tech->tokens()->count());

        Sanctum::actingAs($admin);
        $reset = $this->postJson("/api/staff/{$tech->id}/reset-password", [
            'audit_remarks' => 'Technician forgot password after field phone replacement.',
        ])->assertOk();
        $this->assertSame(User::TEMPORARY_PASSWORD, $reset->json('data.temporary_password'));
        $this->assertTrue($tech->fresh()->must_change_password);
        $this->assertSame(0, $tech->tokens()->count());
    }

    public function test_deactivated_and_locked_users_cannot_login(): void
    {
        $inactive = User::factory()->technician()->create([
            'email' => 'off@mao.com',
            'is_active' => false,
        ]);
        $locked = User::factory()->technician()->create([
            'email' => 'locked@mao.com',
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/login', [
            'email' => $inactive->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertForbidden();

        $this->postJson('/api/login', [
            'email' => $locked->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertUnauthorized();
    }

    public function test_last_super_admin_cannot_be_deactivated(): void
    {
        $root = User::factory()->superAdmin()->create();
        Sanctum::actingAs($root);

        $this->patchJson("/api/staff/{$root->id}", [
            'is_active' => false,
        ])->assertUnprocessable();
    }

    public function test_admin_staff_directory_hides_admins(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        User::factory()->admin()->create(['email' => 'hidden-admin@mao.com']);
        User::factory()->technician()->create(['email' => 'visible-tech@mao.com']);

        $this->getJson('/api/staff')->assertOk()
            ->assertJsonMissing(['email' => 'hidden-admin@mao.com'])
            ->assertJsonFragment(['email' => 'visible-tech@mao.com']);

        $this->getJson('/api/users?role=admin')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_super_admin_can_toggle_enforce_mfa_on_admin_and_revokes_sessions(): void
    {
        $root = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create(['enforce_mfa' => false]);
        $admin->createToken('phpunit');
        $this->assertSame(1, $admin->tokens()->count());

        Sanctum::actingAs($root);
        $this->patchJson("/api/staff/{$admin->id}", [
            'enforce_mfa' => true,
        ])->assertOk()
            ->assertJsonPath('data.enforce_mfa', true);

        $this->assertSame(0, $admin->tokens()->count());
        $this->assertTrue($admin->fresh()->enforce_mfa);
    }

    public function test_admin_cannot_set_enforce_mfa(): void
    {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->technician()->create();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/staff/{$tech->id}", [
            'enforce_mfa' => true,
        ])->assertUnprocessable();

        $this->assertFalse($tech->fresh()->enforce_mfa);
    }

    public function test_super_admin_cannot_set_enforce_mfa_on_technician(): void
    {
        $root = User::factory()->superAdmin()->create();
        $tech = User::factory()->technician()->create();

        Sanctum::actingAs($root);
        $this->patchJson("/api/staff/{$tech->id}", [
            'enforce_mfa' => true,
        ])->assertUnprocessable();
    }
}
