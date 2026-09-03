<?php

namespace Tests\Feature;

use App\Models\Farmer;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Services\SystemAuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SystemAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_farmer_archive_requires_remarks_and_writes_five_ws_row(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'MAO Admin']);
        $farmer = Farmer::factory()->create(['rsbsa_no' => 'IV-02-0423-2026-999']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/farmers/{$farmer->id}")
            ->assertStatus(422);

        $this->deleteJson("/api/farmers/{$farmer->id}", [
            'audit_remarks' => 'Duplicate enrollment after physical ocular inspection.',
        ])->assertOk();

        $log = SystemAuditLog::query()->where('action', 'farmer.archived')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame('rsbsa', $log->module);
        $this->assertSame('DELETE', $log->verb);
        $this->assertSame('MAO Admin', $log->actor_name);
        $this->assertSame('IV-02-0423-2026-999', $log->record_code);
        $this->assertSame('Duplicate enrollment after physical ocular inspection.', $log->remarks);
        $this->assertNotEmpty($log->row_hash);
        $this->assertNotEmpty($log->prev_hash);
        $this->assertNotNull($log->toAuditPayload()['logged_at']);
        $this->assertStringContainsString('UTC+8', $log->toAuditPayload()['logged_at']);
    }

    public function test_secrets_are_redacted_from_audit_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        app(SystemAuditLogger::class)->record('user.updated', $admin, $admin, [
            'before' => ['password' => 'secret123', 'mobile_number' => '09171234567'],
            'after' => ['password' => 'newsecret', 'mobile_number' => '09179876543', 'photo_base64' => 'AAAA'],
            'temporary_password' => 'TempPass99',
            'token' => 'abc',
        ], request());

        $log = SystemAuditLog::query()->where('action', 'user.updated')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', $log->before_state['password'] ?? null);
        $this->assertSame('*******4567', $log->before_state['mobile_number'] ?? null);
        $this->assertSame('[OMITTED]', $log->after_state['photo_base64'] ?? null);
        $this->assertSame('[REDACTED]', $log->metadata['temporary_password'] ?? null);
        $this->assertSame('[REDACTED]', $log->metadata['token'] ?? null);
        $json = json_encode($log->toArray());
        $this->assertStringNotContainsString('secret123', $json);
        $this->assertStringNotContainsString('TempPass99', $json);
    }

    public function test_audit_log_model_rejects_update_and_delete(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        app(SystemAuditLogger::class)->record('auth.login.success', $admin, $admin, [], request());
        $log = SystemAuditLog::query()->latest('created_at')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_csv_export_is_logged(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        app(SystemAuditLogger::class)->record('auth.login.success', $admin, $admin, [], request());

        $this->get('/api/system/audit-logs/export')->assertOk();

        $this->assertDatabaseHas('tbl_system_audit_logs', [
            'action' => 'export.audit_logs',
            'verb' => 'EXPORT',
            'module' => 'export',
        ]);
    }

    public function test_super_admin_can_check_integrity(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/system/audit-logs/integrity')
            ->assertOk()
            ->assertJsonPath('data.valid', true);
    }
}
