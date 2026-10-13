<?php

namespace App\Support;

use App\Models\Barangay;
use Database\Seeders\Concerns\EchagueBarangays;

final class OfficialBarangays
{
    /**
     * Official Echague barangay names (64). Prefer tbl_barangays; fall back to the seeder list.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        try {
            $fromDb = Barangay::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            if ($fromDb !== []) {
                return $fromDb;
            }
        } catch (\Throwable) {
            // Fall through to the compiled Echague list.
        }

        return EchagueBarangays::ALL;
    }

    /**
     * Map PSGC barangay labels to official MAO / tbl_barangays names.
     */
    public static function normalizePsgcName(string $psgcName): string
    {
        $trimmed = trim($psgcName);
        if ($trimmed === '') {
            return '';
        }

        $aliases = [
            'Cabugao' => 'Cabugao (Poblacion)',
            'San Manuel' => 'San Manuel (formerly Atelan)',
            'Silauan Norte' => 'Silauan Norte (Poblacion)',
            'Silauan Sur' => 'Silauan Sur (Poblacion)',
            'Soyung' => 'Soyung (Poblacion)',
            'Taggappan' => 'Taggappan (Poblacion)',
        ];

        if (isset($aliases[$trimmed])) {
            return $aliases[$trimmed];
        }

        foreach (self::names() as $official) {
            if (strcasecmp($official, $trimmed) === 0) {
                return $official;
            }
        }

        return $trimmed;
    }

    /**
     * Names that should match a registry filter: the typed value, its official
     * label, and the short PSGC alias when one exists.
     *
     * @return list<string>
     */
    public static function equivalentNames(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return [];
        }

        $aliases = [
            'Cabugao' => 'Cabugao (Poblacion)',
            'San Manuel' => 'San Manuel (formerly Atelan)',
            'Silauan Norte' => 'Silauan Norte (Poblacion)',
            'Silauan Sur' => 'Silauan Sur (Poblacion)',
            'Soyung' => 'Soyung (Poblacion)',
            'Taggappan' => 'Taggappan (Poblacion)',
        ];

        $names = [$trimmed, self::normalizePsgcName($trimmed)];
        foreach ($aliases as $short => $official) {
            if (strcasecmp($trimmed, $short) === 0 || strcasecmp($trimmed, $official) === 0) {
                $names[] = $short;
                $names[] = $official;
            }
        }

        $unique = [];
        foreach ($names as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $unique[strtolower($candidate)] = $candidate;
        }

        return array_values($unique);
    }

    public static function isEchagueCity(?string $city): bool
    {
        return $city !== null && strcasecmp(trim($city), 'Echague') === 0;
    }
}
