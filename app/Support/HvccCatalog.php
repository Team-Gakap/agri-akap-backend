<?php

namespace App\Support;

class HvccCatalog
{
    public static function categories(): array
    {
        return array_keys(config('hvcc_catalog.categories', []));
    }

    public static function commoditiesFor(?string $category): array
    {
        if ($category === null || $category === '') {
            return [];
        }

        return config('hvcc_catalog.categories.'.$category, []);
    }

    public static function allCommodities(): array
    {
        $lists = array_values(config('hvcc_catalog.categories', []));

        return array_values(array_unique(array_merge(...($lists ?: [[]]))));
    }

    public static function isKnownCommodity(?string $value): bool
    {
        $needle = self::normalize($value);
        if ($needle === '') {
            return false;
        }

        foreach (self::allCommodities() as $commodity) {
            if (self::normalize($commodity) === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function categoryForCommodity(?string $commodity): ?string
    {
        $needle = self::normalize($commodity);
        if ($needle === '') {
            return null;
        }

        foreach (config('hvcc_catalog.categories', []) as $category => $commodities) {
            foreach ($commodities as $name) {
                if (self::normalize($name) === $needle) {
                    return $category;
                }
            }
        }

        return null;
    }

    public static function isTreeFruitCategory(?string $category): bool
    {
        return in_array($category, config('hvcc_catalog.tree_fruit_categories', []), true);
    }

    public static function overrideReasonCodes(): array
    {
        return array_keys(config('hvcc_catalog.override_reasons', []));
    }

    public static function overrideReasonLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return config('hvcc_catalog.override_reasons.'.$code);
    }

    /**
     * Display label for grids and statutory exports: "HVCC (Banana)" or plain Rice/Corn.
     */
    public static function formatCropLabel(?string $cropType, ?string $commodity = null, ?string $category = null): string
    {
        $crop = trim((string) $cropType);
        if (strcasecmp($crop, 'HVCC') !== 0 && ! str_starts_with(strtolower($crop), 'hvcc')) {
            return $crop !== '' ? $crop : '—';
        }

        $detail = trim((string) ($commodity ?: $category));

        return $detail !== '' ? 'HVCC ('.$detail.')' : 'HVCC';
    }

    public static function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * A farm plot matches the encoder's crop when the commodity equals the
     * selected type, or (for HVCC) the specific commodity / a generic high-value plot.
     */
    public static function plotMatches(string $plotCommodity, string $cropType, ?string $hvccCommodity = null): bool
    {
        $plot = self::normalize($plotCommodity);
        $crop = self::normalize($cropType);

        if ($crop === 'hvcc' || str_starts_with($crop, 'hvcc')) {
            if ($hvccCommodity !== null && $hvccCommodity !== '' && $plot === self::normalize($hvccCommodity)) {
                return true;
            }

            return $plot === 'hvcc'
                || str_contains($plot, 'high-value')
                || $plot === 'hvc'
                || str_contains($plot, 'hvc');
        }

        return $plot === $crop;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function optionalFieldRules(): array
    {
        return [
            'crop_category' => ['nullable', 'string', 'max:64'],
            'hvcc_commodity' => ['nullable', 'string', 'max:64'],
            'num_hills_trees' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
