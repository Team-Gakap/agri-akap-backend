<?php

namespace App\Traits;

use App\Services\SystemAuditLogger;
use Illuminate\Database\Eloquent\Model;

trait LogsReportAudit
{
    protected function logReportAudit(string $action, ?Model $target, array $metadata = []): void
    {
        $actor = request()?->user();
        app(SystemAuditLogger::class)->record(
            $action,
            $actor instanceof \App\Models\User ? $actor : null,
            $target,
            $metadata,
            request(),
        );
    }
}
