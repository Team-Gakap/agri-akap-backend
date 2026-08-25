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
}
