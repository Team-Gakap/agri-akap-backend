<?php

namespace App\Traits;

use App\Services\SystemAuditLogger;
use App\Support\AuditRemarks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    /**
     * Non-pending archive/void requires COA justification; pending remove stays optional.
     */
    protected function remarksForArchive(Request $request, bool $isPending, string $message = 'A justification is required before archiving a validated or claimed record.'): ?string
    {
        if (! $isPending) {
            return AuditRemarks::require($request, $message);
        }

        return AuditRemarks::optional($request);
    }
}
