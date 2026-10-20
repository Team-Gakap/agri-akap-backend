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
        if ($key === '' || $key === 'unspecified' || $key === 'other' || $key === 'n/a' || $key === '#n/a') {
            return null;
        }

        if (str_contains($key, 'hvcc') || str_contains($key, 'high-value') || str_contains($key, 'high value')) {
            return null;
        }

        $hasRice = str_contains($key, 'rice') || str_contains($key, 'palay');
        $hasCorn = str_contains($key, 'corn') || str_contains($key, 'maize') || preg_match('/\bmais\b/', $key) === 1;

        if ($hasRice && $hasCorn) {
            return null;
        }
        if ($hasRice) {
            return 'rice';
        }
        if ($hasCorn) {
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
                $inner->whereRaw("LOWER({$column}) like ?", ['%rice%'])
                    ->orWhereRaw("LOWER({$column}) like ?", ['%palay%']);
            });
            self::excludeOtherStaple($query, $column, 'corn');

            return;
        }

        if ($staple === 'corn') {
            $query->where(function ($inner) use ($column) {
                $inner->whereRaw("LOWER({$column}) like ?", ['%corn%'])
                    ->orWhereRaw("LOWER({$column}) like ?", ['%maize%'])
                    ->orWhereRaw("LOWER({$column}) like ?", ['%mais%']);
            });
            self::excludeOtherStaple($query, $column, 'rice');

            return;
        }

        $query->whereRaw('LOWER(TRIM('.$column.')) = ?', [strtolower(trim($cropType))]);
    }

    /**
     * Keep a rice filter from also matching a mixed or corn label, and the reverse.
     */
    private static function excludeOtherStaple($query, string $column, string $other): void
    {
        if ($other === 'corn') {
            $query->whereRaw("LOWER({$column}) not like ?", ['%corn%'])
                ->whereRaw("LOWER({$column}) not like ?", ['%maize%'])
                ->whereRaw("LOWER({$column}) not like ?", ['%mais%']);
        } else {
            $query->whereRaw("LOWER({$column}) not like ?", ['%rice%'])
                ->whereRaw("LOWER({$column}) not like ?", ['%palay%']);
        }

        $query->whereRaw("LOWER({$column}) not like ?", ['%hvcc%'])
            ->whereRaw("LOWER({$column}) not like ?", ['%high-value%'])
            ->whereRaw("LOWER({$column}) not like ?", ['%high value%']);
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
