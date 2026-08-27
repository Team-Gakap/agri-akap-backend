<?php

namespace App\Services;

use App\Models\SystemAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
    ];

    public function record(
        string $action,
        ?User $actor = null,
        ?Model $target = null,
        array $metadata = [],
        ?Request $request = null,
    ): SystemAuditLog {
        $request ??= request();

        return SystemAuditLog::create([
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'actor_role' => $actor?->role,
            'action' => $action,
            'target_type' => $target ? $target->getMorphClass() : null,
            'target_id' => $target?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $this->sanitize($metadata),
            'created_at' => now(),
        ]);
    }

    private function sanitize(array $metadata): array
    {
        foreach (self::REDACT as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }
}
