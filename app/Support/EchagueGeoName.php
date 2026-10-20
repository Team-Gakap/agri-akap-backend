<?php

namespace App\Support;

/**
 * Bridges PSA admin4 GeoJSON names to the masterlist barangay spelling.
 * Mirrors agri-akap-frontend/src/utils/echagueGeoName.ts.
 */
final class EchagueGeoName
{
    public static function normalize(string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';
    }

    public static function toOfficial(string $geoName): string
    {
        $official = OfficialBarangays::canonicalize($geoName);

        return $official !== '' ? $official : trim($geoName);
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
