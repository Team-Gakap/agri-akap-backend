<?php

namespace App\Support;

use App\Models\Farmer;
use App\Models\FarmPlot;

/**
 * Attach the encoder's crop to an imported placeholder plot, or open a new
 * parcel when the farmer already has a plot for a different crop.
 */
final class CropPlotClaimer
{
    public static function isUnspecified(?string $commodity): bool
    {
        $key = strtolower(trim((string) $commodity));

        return $key === '' || $key === strtolower(ImportedFarmPlot::COMMODITY);
    }

    public static function commodityLabel(string $cropType, ?string $unused = null): string
    {
        $crop = trim($cropType);

        return $crop !== '' ? $crop : ImportedFarmPlot::COMMODITY;
    }

    public static function stapleCrop(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));
        $compact = str_replace([' ', '/', '_', '-'], '', $key);
        if ($compact === '') {
            return null;
        }

        if (in_array($compact, ['rice', 'palay', 'ricepalay'], true)
            || (str_contains($key, 'rice') && str_contains($key, 'palay'))) {
            return 'rice';
        }

        if (in_array($compact, ['corn', 'maize', 'mais', 'cornmaize'], true)) {
            return 'corn';
        }

        return null;
    }

    public static function plotMatches(string $plotCommodity, string $cropType, ?string $unused = null): bool
    {
        $plotStaple = self::stapleCrop($plotCommodity);
        $cropStaple = self::stapleCrop($cropType);
        if ($plotStaple !== null && $cropStaple !== null) {
            return $plotStaple === $cropStaple;
        }

        return strtolower(trim($plotCommodity)) === strtolower(trim($cropType));
    }

    public static function wherePlotCommodityMatches($query, string $column, string $cropType): void
    {
        $staple = self::stapleCrop($cropType);
        if ($staple === 'rice') {
            $query->where(function ($inner) use ($column) {
                $inner->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM({$column})), ' ', ''), '/', ''), '_', ''), '-', '') in (?, ?, ?)",
                    ['rice', 'palay', 'ricepalay']
                )->orWhere(function ($both) use ($column) {
                    $both->whereRaw("LOWER({$column}) like ?", ['%rice%'])
                        ->whereRaw("LOWER({$column}) like ?", ['%palay%']);
                });
            });

            return;
        }

        if ($staple === 'corn') {
            $query->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM({$column})), ' ', ''), '/', ''), '_', ''), '-', '') in (?, ?, ?)",
                ['corn', 'maize', 'mais']
            );

            return;
        }

        $query->whereRaw('LOWER(TRIM('.$column.')) = ?', [strtolower(trim($cropType))]);
    }

    /**
     * @return array{plot: FarmPlot}|array{error: string}
     */
    public static function claim(Farmer $farmer, ?string $plotId, string $cropType, ?string $hvccCommodity = null): array
    {
        $label = self::commodityLabel($cropType, $hvccCommodity);
        $plots = FarmPlot::query()->where('farmer_id', $farmer->id)->get();

        if ($plotId) {
            $plot = $plots->firstWhere('id', $plotId);
            if (! $plot) {
                return ['error' => 'Selected farm plot does not belong to this farmer.'];
            }
            if (self::isUnspecified($plot->commodity)) {
                $plot->commodity = $label;
                $plot->save();

                return ['plot' => $plot];
            }
            if (! self::plotMatches((string) $plot->commodity, $cropType)) {
                return ['error' => "Selected plot is {$plot->commodity}, but this form is for {$label} only."];
            }

            return ['plot' => $plot];
        }

        $match = $plots->first(
            fn (FarmPlot $plot) => self::plotMatches((string) $plot->commodity, $cropType)
        );
        if ($match) {
            return ['plot' => $match];
        }

        $placeholder = $plots->first(fn (FarmPlot $plot) => self::isUnspecified($plot->commodity));
        if ($placeholder) {
            $placeholder->commodity = $label;
            $placeholder->save();

            return ['plot' => $placeholder];
        }

        $created = FarmPlot::create(ImportedFarmPlot::attributes($farmer, $label));

        return ['plot' => $created];
    }
}
