<?php

namespace App\Support;

/**
 * MAO / DA field allocation for rice seed bags.
 *
 * Smallholders below 1 ha receive a minimum of 1 bag.
 * Larger areas use round(eligible_ha * bags_per_hectare).
 */
class SubsidyAllocation
{
    /**
     * Bags (or primary units) for a farm area after applying the DA hectare cap.
     */
    public static function bagsForArea(float $farmAreaHa, float $bagsPerHectare = 1.0, ?float $maxHectares = null): int
    {
        if ($farmAreaHa <= 0) {
            return 0;
        }

        $rate = $bagsPerHectare > 0 ? $bagsPerHectare : 1.0;
        $eligible = $farmAreaHa;
        if ($maxHectares !== null && $maxHectares > 0) {
            $eligible = min($eligible, $maxHectares);
        }

        return max(1, (int) round($eligible * $rate));
    }
}
