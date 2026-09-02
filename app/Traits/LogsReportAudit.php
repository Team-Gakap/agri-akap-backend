<?php

namespace App\Traits;

use App\Services\SystemAuditLogger;
use Illuminate\Database\Eloquent\Model;

trait LogsReportAudit
{
    protected function logReportAudit(string $action, ?Model $target, array $metadata = []): void
    {
        app(SystemAuditLogger::class)->record(
            $action,
            request()->user(),
            $target,
            $metadata,
            request(),
        );
    }
}
