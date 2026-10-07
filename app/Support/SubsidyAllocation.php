<?php

namespace App\Support;

/**
 * Subsidy quantity from farm area.
 *
 * Cash and non-seed items use a rounded hectare rate.
 * Seed uses whole hectares: below 1 ha is 1 bag, and 1 ha or more is
 * floor(ha) times the per-hectare rate. Kilograms on a seed campaign use
 * that same whole-hectare count, so 0.5 ha receives one bag's kilograms.
 */
class SubsidyAllocation
{
    /**
     * Rounded units for cash and other non-seed items, after the hectare cap.
     */
    public static function bagsForArea(float $farmAreaHa, float $bagsPerHectare = 1.0, ?float $maxHectares = null): int
    {
        if ($farmAreaHa <= 0) {
            return 0;
        }

        $rate = $bagsPerHectare > 0 ? $bagsPerHectare : 1.0;
        $eligible = self::cappedArea($farmAreaHa, $maxHectares);
        if ($eligible <= 0) {
            return 0;
        }

        return max(1, (int) round($eligible * $rate));
    }

    /**
     * Seed quantity after the hectare cap.
     * Bag units below 1 ha return 1. Other seed units (kg) use one whole hectare times the rate.
     */
    public static function seedQuantity(float $farmAreaHa, float $perHectare, ?float $maxHectares = null, bool $bagUnit = true): int
    {
        if ($farmAreaHa <= 0) {
            return 0;
        }

        $eligible = self::cappedArea($farmAreaHa, $maxHectares);
        if ($eligible <= 0) {
            return 0;
        }

        if ($bagUnit && $eligible < 1) {
            return 1;
        }

        $whole = $eligible < 1 ? 1 : (int) floor($eligible);
        $rate = $perHectare > 0 ? $perHectare : 1.0;

        return max(1, (int) round($whole * $rate));
    }

    public static function isBagUnit(?string $unit): bool
    {
        $normalized = strtolower(trim((string) $unit));

        return $normalized === '' || str_contains($normalized, 'bag');
    }

    private static function cappedArea(float $farmAreaHa, ?float $maxHectares): float
    {
        if ($maxHectares !== null && $maxHectares > 0) {
            return min($farmAreaHa, $maxHectares);
        }

        return $farmAreaHa;
    }
}
