<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /**
     * Strong password for self-service reset and authenticated change.
     *
     * @return list<mixed>
     */
    public static function required(bool $confirmed = true, ?string $differentFrom = null): array
    {
        $rules = [
            'required',
            'string',
            Password::min(8)->mixedCase()->numbers()->symbols(),
            Rule::notIn([User::TEMPORARY_PASSWORD]),
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        if ($differentFrom !== null) {
            $rules[] = 'different:'.$differentFrom;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'password.not_in' => 'The municipal temporary password cannot be reused. Choose a unique password.',
            'password.different' => 'The new password must be different from the current password.',
            'password.confirmed' => 'The new password confirmation does not match.',
        ];
    }
}
