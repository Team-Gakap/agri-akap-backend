<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class CalamityTypes
{
    /** @var list<string> */
    public const ALL = [
        'Typhoon',
        'Earthquake',
        'Flood',
        'Drought',
        'Pest Outbreak',
        'Hail',
        'Fire',
        'Other',
    ];

    public static function rule(): \Illuminate\Validation\Rules\In
    {
        return Rule::in(self::ALL);
    }
}
