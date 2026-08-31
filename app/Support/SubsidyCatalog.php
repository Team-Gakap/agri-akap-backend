<?php

namespace App\Support;

/**
 * Single source of truth for the MAO Hybrid/Inbred subsidy unit matrix.
 * Rice and Corn campaigns both use this table; only `target_crop` differs.
 * Mirrors agri-akap-frontend/src/constants/subsidyCatalog.ts — keep in sync.
 */
class SubsidyCatalog
{
    public const HYBRID = 'Hybrid';
    public const INBRED = 'Inbred';

    public const SEED = 'seed';
    public const ABONO = 'abono';
    public const LIQUID_FERTILIZER = 'liquid_fertilizer';
    public const WETTABLE = 'wettable';
    public const CASH = 'cash';

    /**
     * @var array<string, array<string, array{label: string, unit: string, secondary_unit: ?string, is_cash: bool}>>
     */
    private const CATALOG = [
        self::HYBRID => [
            self::SEED => ['label' => 'Seed', 'unit' => 'kg', 'secondary_unit' => 'bags', 'is_cash' => false],
            self::ABONO => ['label' => 'Abono', 'unit' => 'kg', 'secondary_unit' => 'bags', 'is_cash' => false],
            self::LIQUID_FERTILIZER => ['label' => 'Liquid Fertilizer', 'unit' => 'bottle', 'secondary_unit' => null, 'is_cash' => false],
            self::WETTABLE => ['label' => 'Wettable', 'unit' => 'kg', 'secondary_unit' => 'packs', 'is_cash' => false],
            self::CASH => ['label' => 'Cash Assistance', 'unit' => 'Cash (PHP)', 'secondary_unit' => null, 'is_cash' => true],
        ],
        self::INBRED => [
            self::SEED => ['label' => 'Seed', 'unit' => 'bags', 'secondary_unit' => null, 'is_cash' => false],
        ],
    ];

    /**
     * @return string[]
     */
    public static function seedClasses(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * @return string[]
     */
    public static function itemTypesFor(?string $seedClass): array
    {
        return array_keys(self::CATALOG[$seedClass] ?? []);
    }

    public static function isValidCombo(?string $seedClass, ?string $itemType): bool
    {
        if (! $seedClass || ! $itemType) {
            return false;
        }

        return isset(self::CATALOG[$seedClass][$itemType]);
    }

    /**
     * @return array{label: string, unit: string, secondary_unit: ?string, is_cash: bool}|null
     */
    public static function entry(?string $seedClass, ?string $itemType): ?array
    {
        if (! $seedClass || ! $itemType) {
            return null;
        }

        return self::CATALOG[$seedClass][$itemType] ?? null;
    }

    public static function unit(?string $seedClass, ?string $itemType): ?string
    {
        return self::entry($seedClass, $itemType)['unit'] ?? null;
    }

    public static function secondaryUnit(?string $seedClass, ?string $itemType): ?string
    {
        return self::entry($seedClass, $itemType)['secondary_unit'] ?? null;
    }

    public static function isDualUnit(?string $seedClass, ?string $itemType): bool
    {
        return self::secondaryUnit($seedClass, $itemType) !== null;
    }

    public static function isCash(?string $itemType): bool
    {
        return $itemType === self::CASH;
    }

    public static function label(?string $seedClass, ?string $itemType): ?string
    {
        return self::entry($seedClass, $itemType)['label'] ?? null;
    }

    /**
     * @return array<string, array<string, array{label: string, unit: string, secondary_unit: ?string, is_cash: bool}>>
     */
    public static function all(): array
    {
        return self::CATALOG;
    }
}
