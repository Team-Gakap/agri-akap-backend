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

    public static function commodityLabel(string $cropType, ?string $hvccCommodity = null): string
    {
        $crop = trim($cropType);
        $hvcc = trim((string) $hvccCommodity);
        $key = strtolower($crop);

        if ($key === 'hvcc' || str_starts_with($key, 'hvcc') || $key === 'high-value' || $key === 'high-value crops' || $key === 'hvc') {
            return $hvcc !== '' ? $hvcc : 'High-Value Crops';
        }

        return $crop !== '' ? $crop : ImportedFarmPlot::COMMODITY;
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
            if (! HvccCatalog::plotMatches((string) $plot->commodity, $cropType, $hvccCommodity)) {
                return ['error' => "Selected plot is {$plot->commodity}, but this form is for {$label} only."];
            }

            return ['plot' => $plot];
        }

        $match = $plots->first(
            fn (FarmPlot $plot) => HvccCatalog::plotMatches((string) $plot->commodity, $cropType, $hvccCommodity)
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
