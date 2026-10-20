<?php

namespace App\Support;

use App\Models\Farmer;

/**
 * Farm address is the classification key (masterlist farm barangay).
 * Residence (permanent_brgy) is only a fallback when farm_brgy was never stored.
 */
final class FarmAddressSync
{
    /**
     * Copy the first submitted parcel location onto the farmer row.
     *
     * @param  list<array<string, mixed>>  $plots
     */
    public static function syncFromPlotPayload(Farmer $farmer, array $plots): void
    {
        $first = null;
        foreach ($plots as $plot) {
            if (is_array($plot)) {
                $first = $plot;
                break;
            }
        }
        if ($first === null) {
            return;
        }

        self::apply(
            $farmer,
            isset($first['location_brgy']) ? (string) $first['location_brgy'] : null,
            isset($first['location_city']) ? (string) $first['location_city'] : null,
        );
    }

    /**
     * Re-read the earliest parcel so registry plot edits stay aligned with classification.
     */
    public static function syncFromStoredPlots(Farmer $farmer): void
    {
        $plot = $farmer->farmPlots()->orderBy('created_at')->orderBy('id')->first();
        if ($plot === null) {
            return;
        }

        self::apply($farmer, $plot->location_brgy, $plot->location_city);
    }

    public static function apply(Farmer $farmer, ?string $barangay, ?string $city): void
    {
        $updates = [];
        $brgy = trim((string) $barangay);
        $cityName = trim((string) $city);
        if ($brgy !== '') {
            $updates['farm_brgy'] = $brgy;
        }
        if ($cityName !== '') {
            $updates['farm_city'] = $cityName;
        }
        if ($updates === []) {
            return;
        }

        $farmer->fill($updates);
        $farmer->save();
    }

    public static function label(?string $farmBrgy, ?string $permanentBrgy): string
    {
        $farm = trim((string) $farmBrgy);
        if ($farm !== '') {
            return $farm;
        }
        $home = trim((string) $permanentBrgy);

        return $home !== '' ? $home : 'Unspecified';
    }

    public static function belongsToBarangay(Farmer $farmer, string $barangay): bool
    {
        $farm = trim((string) ($farmer->farm_brgy ?: $farmer->permanent_brgy));
        if ($farm === '' || trim($barangay) === '') {
            return false;
        }

        foreach (OfficialBarangays::equivalentNames($barangay) as $name) {
            if (strcasecmp($farm, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * LOWER(TRIM(COALESCE(farm_brgy, permanent_brgy)))
     */
    public static function sqlLower(string $table = 'farmers'): string
    {
        return "LOWER(TRIM(COALESCE(NULLIF({$table}.farm_brgy, ''), {$table}.permanent_brgy)))";
    }

    /**
     * Display label: farm barangay, then residence, then Unspecified.
     */
    public static function sqlDisplay(string $table = 'farmers'): string
    {
        return "COALESCE(NULLIF(TRIM({$table}.farm_brgy), ''), NULLIF(TRIM({$table}.permanent_brgy), ''), 'Unspecified')";
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $barangays
     */
    public static function whereIn($query, array $barangays, string $table = 'farmers'): void
    {
        $names = [];
        foreach ($barangays as $barangay) {
            if (! is_string($barangay) && ! is_numeric($barangay)) {
                continue;
            }
            foreach (OfficialBarangays::equivalentNames((string) $barangay) as $name) {
                $key = strtolower($name);
                $names[$key] = $key;
            }
        }

        $names = array_values($names);
        if ($names === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $query->whereRaw(self::sqlLower($table)." IN ($placeholders)", $names);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function constrain($query, string $barangay, string $table = 'farmers'): void
    {
        self::whereIn($query, [$barangay], $table);
    }
}
