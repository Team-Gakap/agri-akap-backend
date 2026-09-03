<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuditRemarks
{
    public static function require(?Request $request = null, string $message = 'A justification is required for this action (COA audit trail).'): string
    {
        $value = self::optional($request);
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'audit_remarks' => $message,
            ]);
        }

        return $value;
    }

    public static function optional(?Request $request = null): ?string
    {
        $request ??= request();
        if (! $request instanceof Request) {
            return null;
        }

        foreach (['audit_remarks', 'remarks', 'reason'] as $key) {
            $raw = $request->input($key);
            if (! is_string($raw)) {
                continue;
            }
            $trimmed = trim($raw);
            if ($trimmed !== '') {
                return Str::limit($trimmed, 1000, '');
            }
        }

        return null;
    }
}
