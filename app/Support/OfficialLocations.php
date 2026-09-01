<?php

namespace App\Support;

final class OfficialLocations
{
    /** @return list<string> */
    public static function regions(): array
    {
        return [
            'Region II',
            'National Capital Region (NCR)',
        ];
    }

    /** @return list<string> */
    public static function provinces(): array
    {
        return [
            'Isabela',
            'Metro Manila',
        ];
    }

    /** @return list<string> */
    public static function cities(): array
    {
        return [
            'Echague',
            'Caloocan',
            'Las Piñas',
            'Makati',
            'Malabon',
            'Mandaluyong',
            'Manila',
            'Marikina',
            'Muntinlupa',
            'Navotas',
            'Parañaque',
            'Pasay',
            'Pasig',
            'Pateros',
            'Quezon City',
            'San Juan',
            'Taguig',
            'Valenzuela',
        ];
    }

    /**
     * @return array{
     *   regions: list<string>,
     *   provinces: list<string>,
     *   cities: list<string>,
     *   defaults: array{region: string, province: string, city: string}
     * }
     */
    public static function catalog(): array
    {
        return [
            'regions' => self::regions(),
            'provinces' => self::provinces(),
            'cities' => self::cities(),
            'defaults' => [
                'region' => 'Region II',
                'province' => 'Isabela',
                'city' => 'Echague',
            ],
        ];
    }
}
