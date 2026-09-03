<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use App\Support\AuditCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Append-only COA audit trail. Rows are retained for 5–10 years (no auto-purge).
 * Updates and deletes are blocked in Eloquent and by database triggers.
 */
class SystemAuditLog extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'tbl_system_audit_logs';

    protected $fillable = [
        'id',
        'actor_id',
        'actor_email',
        'actor_name',
        'actor_role',
        'action',
        'verb',
        'module',
        'target_type',
        'target_id',
        'target_table',
        'record_code',
        'ip_address',
        'user_agent',
        'metadata',
        'before_state',
        'after_state',
        'remarks',
        'prev_hash',
        'row_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $log): never {
            self::flagTamper($log, 'update');
            throw new RuntimeException('Audit logs are append-only.');
        });

        static::deleting(function (self $log): never {
            self::flagTamper($log, 'delete');
            throw new RuntimeException('Audit logs are append-only.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function genesisHash(): string
    {
        return str_repeat('0', 64);
    }

    /** @param  array<string, mixed>  $fields */
    public static function computeRowHash(array $fields): string
    {
        $canonical = [
            'id' => (string) ($fields['id'] ?? ''),
            'created_at' => (string) ($fields['created_at'] ?? ''),
            'actor_id' => (string) ($fields['actor_id'] ?? ''),
            'action' => (string) ($fields['action'] ?? ''),
            'target_id' => (string) ($fields['target_id'] ?? ''),
            'before_state' => $fields['before_state'] ?? null,
            'after_state' => $fields['after_state'] ?? null,
            'remarks' => (string) ($fields['remarks'] ?? ''),
            'prev_hash' => (string) ($fields['prev_hash'] ?? self::genesisHash()),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function toAuditPayload(): array
    {
        $pst = $this->created_at?->clone()->setTimezone(AuditCatalog::TIMEZONE);

        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'logged_at' => $pst ? $pst->format('Y-m-d H:i:s').' UTC+8' : null,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'actor_email' => $this->actor_email,
            'actor_role' => $this->actor_role,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'action' => $this->action,
            'verb' => $this->verb,
            'module' => $this->module,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_table' => $this->target_table,
            'record_code' => $this->record_code,
            'before_state' => $this->before_state,
            'after_state' => $this->after_state,
            'remarks' => $this->remarks,
            'metadata' => $this->metadata,
            'actor' => [
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
                'role' => $this->actor_role,
                'ip_address' => $this->ip_address,
            ],
            'target' => [
                'type' => $this->target_type,
                'id' => $this->target_id,
                'table' => $this->target_table,
                'record_code' => $this->record_code,
            ],
        ];
    }

    private static function flagTamper(self $log, string $attempt): void
    {
        try {
            AuditIntegrityAlert::query()->create([
                'audit_log_id' => $log->id,
                'attempt' => $attempt,
                'actor_id' => Auth::id(),
                'detail' => 'Application attempted to '.$attempt.' an audit log.',
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable) {
            // Never block the immutability exception if the alert table is unavailable.
        }
    }
}
