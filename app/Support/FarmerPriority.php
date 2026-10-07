<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Senior / PWD priority flags for subsidy eligibility UIs.
 */
class FarmerPriority
{
    public static function isSeniorCitizen(mixed $birthdate): bool
    {
        if ($birthdate === null || $birthdate === '') {
            return false;
        }

        try {
            return Carbon::parse($birthdate)->age >= 60;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{is_pwd: bool, is_senior: bool, priority_label: string|null}
     */
    public static function flags(bool $isPwd, mixed $birthdate): array
    {
        $isSenior = self::isSeniorCitizen($birthdate);
        $label = null;
        if ($isPwd && $isSenior) {
            $label = 'Senior / PWD';
        } elseif ($isSenior) {
            $label = 'Senior';
        } elseif ($isPwd) {
            $label = 'PWD';
        }

        return [
            'is_pwd' => $isPwd,
            'is_senior' => $isSenior,
            'priority_label' => $label,
        ];
    }
}
