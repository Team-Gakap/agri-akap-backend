<?php

namespace App\Support;

use App\Models\Farmer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One unmapped parcel for an RSBSA row that was imported without farm_plots.
 */
final class ImportedFarmPlot
{
    public const COMMODITY = 'Unspecified';

    /**
     * @param  Farmer|array<string, mixed>  $farmer
     * @return array<string, mixed>
     */
    public static function attributes(Farmer|array $farmer, ?string $commodity = null): array
    {
        $get = function (string $key) use ($farmer) {
            if (is_array($farmer)) {
                return $farmer[$key] ?? null;
            }

            return $farmer->{$key} ?? null;
        };

        $area = round((float) ($get('total_farm_area_ha') ?? 0), 4);
        $barangay = trim((string) ($get('farm_brgy') ?: $get('permanent_brgy') ?: ''));
        $city = trim((string) ($get('farm_city') ?: $get('permanent_city') ?: OfficialLocations::catalog()['defaults']['city']));
        $province = trim((string) ($get('permanent_province') ?: OfficialLocations::catalog()['defaults']['province']));
        $now = now()->toDateTimeString();

        return [
            'id' => (string) Str::uuid(),
            'farmer_id' => $get('id'),
            'location_brgy' => $barangay !== '' ? $barangay : 'Unknown',
            'location_city' => $city !== '' ? $city : 'Echague',
            'location_province' => $province !== '' ? $province : 'Isabela',
            'total_parcel_area_ha' => $area,
            'size_ha' => $area,
            'ownership_type' => 'Others',
            'proof_of_ownership_document' => 'Pending Manual Verification',
            'commodity' => ($commodity !== null && trim($commodity) !== '') ? trim($commodity) : self::COMMODITY,
            'farm_type' => 'Other',
            'farm_type_other' => 'Pending manual verification',
            'geotag_status' => 'unmapped',
            'is_ancestral_domain' => 0,
            'is_agrarian_reform_beneficiary' => 0,
            'is_organic' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Insert one plot for each of these RSBSA numbers that still has none.
     *
     * @param  list<string>  $rsbsaNos
     * @param  array<string, string|null>  $commodityByRsbsa
     */
    public static function insertMissingByRsbsa(array $rsbsaNos, array $commodityByRsbsa = []): int
    {
        $rsbsaNos = array_values(array_unique(array_filter($rsbsaNos)));
        if ($rsbsaNos === []) {
            return 0;
        }

        $farmers = Farmer::query()
            ->whereIn('rsbsa_no', $rsbsaNos)
            ->whereDoesntHave('farmPlots')
            ->get();

        $inserted = self::insertFarmers($farmers, $commodityByRsbsa);
        self::fillUnspecifiedCommodity($commodityByRsbsa);

        return $inserted;
    }

    /**
     * Re-uploads should replace the placeholder commodity on plots created
     * before COMMODITY was mapped. Plots with a real commodity are left as-is.
     *
     * @param  array<string, string|null>  $commodityByRsbsa
     */
    public static function fillUnspecifiedCommodity(array $commodityByRsbsa): int
    {
        $idsByCommodity = [];
        foreach ($commodityByRsbsa as $rsbsa => $commodity) {
            $label = is_string($commodity) ? trim($commodity) : '';
            $rsbsa = trim((string) $rsbsa);
            if ($rsbsa === '' || $label === '' || strcasecmp($label, self::COMMODITY) === 0) {
                continue;
            }
            $idsByCommodity[$label][] = $rsbsa;
        }

        $updated = 0;
        foreach ($idsByCommodity as $label => $rsbsaNos) {
            $farmerIds = Farmer::query()->whereIn('rsbsa_no', $rsbsaNos)->pluck('id');
            if ($farmerIds->isEmpty()) {
                continue;
            }
            $updated += DB::table('farm_plots')
                ->whereIn('farmer_id', $farmerIds->all())
                ->whereNull('deleted_at')
                ->where(function ($query) {
                    $query->whereNull('commodity')
                        ->orWhere('commodity', '')
                        ->orWhereRaw('LOWER(commodity) = ?', [strtolower(self::COMMODITY)]);
                })
                ->update([
                    'commodity' => $label,
                    'updated_at' => now()->toDateTimeString(),
                ]);
        }

        return $updated;
    }

    /** Insert one plot for every farmer who currently has none. */
    public static function insertMissingForAll(): int
    {
        $ids = Farmer::query()->whereDoesntHave('farmPlots')->pluck('id');
        $inserted = 0;
        foreach ($ids->chunk(200) as $chunk) {
            $farmers = Farmer::query()->whereIn('id', $chunk->all())->get();
            $inserted += self::insertFarmers($farmers, []);
        }

        return $inserted;
    }

    /**
     * @param  array<string, string|null>  $commodityByRsbsa
     */
    private static function insertFarmers(iterable $farmers, array $commodityByRsbsa = []): int
    {
        $rows = [];
        foreach ($farmers as $farmer) {
            $commodity = $commodityByRsbsa[$farmer->rsbsa_no] ?? null;
            $rows[] = self::attributes($farmer, is_string($commodity) ? $commodity : null);
        }
        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('farm_plots')->insert($chunk);
        }

        return count($rows);
    }
}
