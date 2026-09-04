<?php

namespace App\Support;

/**
 * Bridges PSA admin4 GeoJSON short names to official tbl_barangays / weather names.
 * Mirrors agri-akap-frontend/src/utils/echagueGeoName.ts and BarangayCoordinateSeeder.
 */
final class EchagueGeoName
{
    /** @var array<string, string> normalized geo name => official name */
    private const GEO_TO_OFFICIAL = [
        'cabugao (pob.)' => 'Cabugao (Poblacion)',
        'san manuel' => 'San Manuel (formerly Atelan)',
        'silauan norte (pob.)' => 'Silauan Norte (Poblacion)',
        'silauan sur (pob.)' => 'Silauan Sur (Poblacion)',
        'soyung' => 'Soyung (Poblacion)',
        'taggappan' => 'Taggappan (Poblacion)',
    ];

    public static function normalize(string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';
    }

    public static function toOfficial(string $geoName): string
    {
        $key = self::normalize($geoName);

        return self::GEO_TO_OFFICIAL[$key] ?? trim($geoName);
    }

    public static function shortLabel(string $name): string
    {
        return str_replace(
            [' (Poblacion)', ' (formerly Atelan)'],
            '',
            $name
        );
    }
}
