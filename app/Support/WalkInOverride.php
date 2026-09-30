<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WalkInOverride
{
    /**
     * Password re-entry plus a statutory reason code (or legacy free-text reason).
     *
     * @param  array<string, mixed>  $validated
     * @return array{reason: string, reason_code: ?string, overridden_by: string}|array{error: string, status: int}
     */
    public static function resolve(array $validated, ?string $userId, bool $requireReasonCode = false): array
    {
        $code = trim((string) ($validated['override_reason_code'] ?? ''));
        $notes = trim((string) ($validated['override_justification'] ?? $validated['override_reason'] ?? ''));
        $label = HvccCatalog::overrideReasonLabel($code);

        if ($requireReasonCode && $label === null) {
            return [
                'error' => 'Select a valid administrative reason before releasing assistance to an unregistered farmer.',
                'status' => 422,
            ];
        }

        $reason = '';
        if ($label !== null) {
            $reason = $notes !== '' ? $label.' — '.$notes : $label;
        } elseif ($notes !== '') {
            $reason = $notes;
        } else {
            try {
                $reason = AuditRemarks::require(
                    request(),
                    'An admin override reason is required to release subsidies to an unverified walk-in farmer.'
                );
            } catch (ValidationException) {
                $reason = '';
            }
        }

        $password = (string) ($validated['override_password'] ?? '');
        $user = $userId ? User::query()->find($userId) : null;

        if ($reason === '' || $password === '') {
            return [
                'error' => 'An admin override password and a written justification are required to release a subsidy to an unverified walk-in farmer.',
                'status' => 422,
            ];
        }

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            return [
                'error' => 'Admin override password is incorrect.',
                'status' => 422,
            ];
        }

        return [
            'reason' => $reason,
            'reason_code' => $label !== null ? $code : null,
            'overridden_by' => $user->id,
        ];
    }
}
