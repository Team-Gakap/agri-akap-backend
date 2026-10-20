<?php

namespace App\Support;

use App\Models\Barangay;
use Database\Seeders\Concerns\EchagueBarangays;

final class OfficialBarangays
{
    /**
     * Masterlist spellings plus older app and GeoJSON labels for the six
     * barangays whose names changed.
     *
     * @var array<string, list<string>>
     */
    private const ALIAS_GROUPS = [
        'Cabugao Pob.' => [
            'Cabugao Pob.',
            'CABUGAO POB.',
            'Cabugao (Poblacion)',
            'Cabugao (Pob.)',
            'Cabugao',
        ],
        'San Manuel' => [
            'San Manuel',
            'SAN MANUEL',
            'San Manuel (formerly Atelan)',
        ],
        'Silauan Norte Pob.' => [
            'Silauan Norte Pob.',
            'SILAUAN NORTE POB.',
            'Silauan Norte (Poblacion)',
            'Silauan Norte (Pob.)',
            'Silauan Norte',
        ],
        'Silauan Sur Pob.' => [
            'Silauan Sur Pob.',
            'SILAUAN SUR POB.',
            'Silauan Sur (Poblacion)',
            'Silauan Sur (Pob.)',
            'Silauan Sur',
        ],
        'Soyung' => [
            'Soyung',
            'SOYUNG',
            'Soyung (Poblacion)',
        ],
        'Taggappan' => [
            'Taggappan',
            'TAGGAPPAN',
            'Taggappan (Poblacion)',
        ],
    ];

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
     * Map a sheet, PSGC, or previously stored label onto the masterlist spelling.
     * Unknown names are returned trimmed and otherwise unchanged.
     */
    public static function canonicalize(?string $name): string
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            return '';
        }

        $key = self::normalizeKey($trimmed);
        foreach (self::ALIAS_GROUPS as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (self::normalizeKey($alias) === $key) {
                    return $canonical;
                }
            }
        }

        foreach (EchagueBarangays::ALL as $official) {
            if (self::normalizeKey($official) === $key) {
                return $official;
            }
        }

        return $trimmed;
    }

    /**
     * Map PSGC barangay labels to official MAO / tbl_barangays names.
     */
    public static function normalizePsgcName(string $psgcName): string
    {
        return self::canonicalize($psgcName);
    }

    /**
     * Names that should match a registry filter: the typed value, its official
     * label, and every known alias for that barangay.
     *
     * @return list<string>
     */
    public static function equivalentNames(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return [];
        }

        $canonical = self::canonicalize($trimmed);
        $names = [$trimmed, $canonical];
        foreach (self::ALIAS_GROUPS as $groupCanonical => $aliases) {
            $matchesGroup = self::normalizeKey($canonical) === self::normalizeKey($groupCanonical);
            if (! $matchesGroup) {
                foreach ($aliases as $alias) {
                    if (self::normalizeKey($alias) === self::normalizeKey($trimmed)) {
                        $matchesGroup = true;
                        break;
                    }
                }
            }
            if ($matchesGroup) {
                $names[] = $groupCanonical;
                array_push($names, ...$aliases);
                break;
            }
        }

        $unique = [];
        foreach ($names as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $unique[self::normalizeKey($candidate)] = $candidate;
        }

        return array_values($unique);
    }

    public static function isEchagueCity(?string $city): bool
    {
        return $city !== null && strcasecmp(trim($city), 'Echague') === 0;
    }

    private static function normalizeKey(string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';
    }
}
