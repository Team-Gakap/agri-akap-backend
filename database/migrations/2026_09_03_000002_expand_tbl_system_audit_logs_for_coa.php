<?php

use App\Models\SystemAuditLog;
use App\Support\AuditCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_audit_integrity_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('audit_log_id')->nullable()->index();
            $table->string('attempt', 32);
            $table->uuid('actor_id')->nullable()->index();
            $table->string('detail', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::table('tbl_system_audit_logs', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('actor_email');
            $table->string('module', 32)->nullable()->index()->after('action');
            $table->string('verb', 16)->nullable()->index()->after('module');
            $table->string('target_table')->nullable()->after('target_id');
            $table->string('record_code')->nullable()->index()->after('target_table');
            $table->json('before_state')->nullable()->after('metadata');
            $table->json('after_state')->nullable()->after('before_state');
            $table->text('remarks')->nullable()->after('after_state');
            $table->char('prev_hash', 64)->nullable()->after('remarks');
            $table->char('row_hash', 64)->nullable()->index()->after('prev_hash');
        });

        $this->backfillHashes();

        DB::unprepared('DROP TRIGGER IF EXISTS trg_system_audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_system_audit_logs_no_delete');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_system_audit_logs_no_update
BEFORE UPDATE ON tbl_system_audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_system_audit_logs_no_delete
BEFORE DELETE ON tbl_system_audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only.';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_system_audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_system_audit_logs_no_delete');

        Schema::table('tbl_system_audit_logs', function (Blueprint $table) {
            $table->dropColumn([
                'actor_name', 'module', 'verb', 'target_table', 'record_code',
                'before_state', 'after_state', 'remarks', 'prev_hash', 'row_hash',
            ]);
        });

        Schema::dropIfExists('tbl_audit_integrity_alerts');
    }

    private function backfillHashes(): void
    {
        $prev = SystemAuditLog::genesisHash();
        $users = DB::table('users')->pluck('name', 'id');

        foreach (DB::table('tbl_system_audit_logs')->orderBy('created_at')->orderBy('id')->cursor() as $row) {
            $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
            if (! is_array($meta)) {
                $meta = [];
            }
            $before = $meta['before'] ?? null;
            $after = $meta['after'] ?? null;
            $createdAt = $row->created_at
                ? \Carbon\Carbon::parse($row->created_at)->utc()->format('Y-m-d H:i:s')
                : '';
            $hash = SystemAuditLog::computeRowHash([
                'id' => $row->id,
                'created_at' => $createdAt,
                'actor_id' => $row->actor_id,
                'action' => $row->action,
                'target_id' => $row->target_id,
                'before_state' => is_array($before) ? $before : null,
                'after_state' => is_array($after) ? $after : null,
                'remarks' => '',
                'prev_hash' => $prev,
            ]);

            DB::table('tbl_system_audit_logs')->where('id', $row->id)->update([
                'actor_name' => $row->actor_id ? ($users[$row->actor_id] ?? null) : null,
                'module' => AuditCatalog::inferModule((string) $row->action),
                'verb' => AuditCatalog::inferVerb((string) $row->action),
                'before_state' => $before !== null ? json_encode($before) : null,
                'after_state' => $after !== null ? json_encode($after) : null,
                'prev_hash' => $prev,
                'row_hash' => $hash,
            ]);

            $prev = $hash;
        }
    }
};
