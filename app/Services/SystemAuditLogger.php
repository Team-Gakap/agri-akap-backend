<?php

namespace App\Services;

use App\Models\SystemAuditLog;
use App\Models\User;
use App\Support\AuditCatalog;
use App\Support\AuditRemarks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemAuditLogger
{
    /** @var list<string> */
    private const REDACT = [
        'password',
        'current_password',
        'password_confirmation',
        'temporary_password',
        'access_token',
        'token',
        'mfa_secret',
        'pending_secret',
        'totp',
        'recovery_code',
        'recovery_codes',
        'sms_code',
        'sms_code_hash',
        'remember_token',
        'otp',
    ];

    /** @var list<string> */
    private const MASK_MOBILE = [
        'mobile_number',
        'mobile',
        'phone',
        'contact_number',
        'masked_mobile',
    ];

    public function record(
        string $action,
        ?User $actor = null,
        ?Model $target = null,
        array $metadata = [],
        ?Request $request = null,
    ): SystemAuditLog {
        $request ??= request();
        $actor ??= $request instanceof Request ? $request->user() : null;
        if ($actor && ! $actor instanceof User) {
            $actor = null;
        }

        $before = $this->extractState($metadata, 'before');
        $after = $this->extractState($metadata, 'after');
        unset($metadata['before'], $metadata['after']);

        $remarks = $metadata['remarks'] ?? $metadata['audit_remarks'] ?? null;
        unset($metadata['remarks'], $metadata['audit_remarks']);
        if (! is_string($remarks) || trim($remarks) === '') {
            $remarks = AuditRemarks::optional($request instanceof Request ? $request : null);
        }

        $recordCode = isset($metadata['record_code']) ? (string) $metadata['record_code'] : $this->recordCode($target);
        unset($metadata['record_code']);

        $module = isset($metadata['module']) && is_string($metadata['module'])
            ? $metadata['module']
            : AuditCatalog::inferModule($action);
        unset($metadata['module']);

        $verb = isset($metadata['verb']) && is_string($metadata['verb'])
            ? $metadata['verb']
            : AuditCatalog::inferVerb($action);
        unset($metadata['verb']);

        $targetTable = isset($metadata['target_table']) && is_string($metadata['target_table'])
            ? $metadata['target_table']
            : ($target?->getTable());
        unset($metadata['target_table']);

        $id = (string) Str::uuid();
        $createdAt = now();
        $createdAtKey = $createdAt->clone()->utc()->format('Y-m-d H:i:s');

        $cleanBefore = $before !== null ? $this->sanitize($before) : null;
        $cleanAfter = $after !== null ? $this->sanitize($after) : null;
        $cleanMeta = $metadata !== [] ? $this->sanitize($metadata) : null;

        return DB::transaction(function () use (
            $id, $createdAt, $createdAtKey, $action, $actor, $target, $request,
            $module, $verb, $targetTable, $recordCode, $cleanBefore, $cleanAfter,
            $remarks, $cleanMeta,
        ) {
            $previous = SystemAuditLog::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevHash = $previous?->row_hash ?: SystemAuditLog::genesisHash();
            $rowHash = SystemAuditLog::computeRowHash([
                'id' => $id,
                'created_at' => $createdAtKey,
                'actor_id' => $actor?->id,
                'action' => $action,
                'target_id' => $target?->getKey(),
                'before_state' => $cleanBefore,
                'after_state' => $cleanAfter,
                'remarks' => (string) ($remarks ?? ''),
                'prev_hash' => $prevHash,
            ]);

            return SystemAuditLog::create([
                'id' => $id,
                'actor_id' => $actor?->id,
                'actor_email' => $actor?->email,
                'actor_name' => $actor?->name,
                'actor_role' => $actor?->role,
                'action' => $action,
                'verb' => $verb,
                'module' => $module,
                'target_type' => $target ? $target->getMorphClass() : null,
                'target_id' => $target?->getKey(),
                'target_table' => $targetTable,
                'record_code' => $recordCode,
                'ip_address' => $request instanceof Request ? $request->ip() : null,
                'user_agent' => $request instanceof Request ? $request->userAgent() : null,
                'metadata' => $cleanMeta,
                'before_state' => $cleanBefore,
                'after_state' => $cleanAfter,
                'remarks' => $remarks,
                'prev_hash' => $prevHash,
                'row_hash' => $rowHash,
                'created_at' => $createdAt,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function fieldDiff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $left = $this->scalarize($before[$key] ?? null);
            $right = $this->scalarize($after[$key] ?? null);
            if ($left !== $right) {
                $changedBefore[$key] = $left;
                $changedAfter[$key] = $right;
            }
        }

        return [$changedBefore, $changedAfter];
    }

    /** @return array<string, mixed>|null */
    private function extractState(array $metadata, string $key): ?array
    {
        if (! array_key_exists($key, $metadata) || $metadata[$key] === null) {
            return null;
        }
        if (! is_array($metadata[$key])) {
            return [$key => $this->scalarize($metadata[$key])];
        }

        return $metadata[$key];
    }

    private function recordCode(?Model $target): ?string
    {
        if (! $target) {
            return null;
        }

        foreach (['rsbsa_no', 'program_name', 'email', 'farmer_rsbsa_no', 'georef_id', 'transaction_code'] as $attr) {
            $value = $target->getAttribute($attr);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $key = $target->getKey();

        return $key !== null ? (string) $key : null;
    }

    private function sanitize(mixed $value, string $key = '', int $depth = 0): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $child) {
                $lk = strtolower((string) $childKey);
                if (in_array($lk, self::REDACT, true) || ($depth === 0 && $lk === 'code')) {
                    $out[$childKey] = '[REDACTED]';
                    continue;
                }
                if (str_contains($lk, 'base64') || str_contains($lk, 'photo_proof')) {
                    $out[$childKey] = '[OMITTED]';
                    continue;
                }
                if (in_array($lk, self::MASK_MOBILE, true)) {
                    $out[$childKey] = $this->maskMobile($child);
                    continue;
                }
                $out[$childKey] = $this->sanitize($child, $lk, $depth + 1);
            }

            return $out;
        }

        if (in_array($key, self::MASK_MOBILE, true)) {
            return $this->maskMobile($value);
        }

        return $this->scalarize($value);
    }

    private function maskMobile(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    private function scalarize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_object($value)) {
            return method_exists($value, 'toArray') ? $value->toArray() : (string) json_encode($value);
        }

        return $value;
    }
}
