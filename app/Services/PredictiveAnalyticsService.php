<?php

namespace App\Services;

use App\Models\DamageAssessment;
use App\Models\PestOutbreak;
use App\Models\WeatherCache;
use App\Support\OfficialBarangays;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barangay crop-yield forecast and agricultural risk index for MAO planning.
 *
 * Projected kilograms = active planted hectares × seasonal kilograms per hectare.
 * The rate prefers that barangay's harvest history for the same season, then the
 * municipal seasonal average, then assumed rates (rice 4,500, corn 5,000, HVCC 3,500).
 * With two or more historical seasons the rate is 70% pooled seasonal yield per
 * hectare and 30% a least-squares trend.
 */
class PredictiveAnalyticsService
{
    public const BUFFER_BAGS_PER_HA = 4;

    public const HISTORY_WEIGHT = 0.7;

    public const TREND_WEIGHT = 0.3;

    /**
     * @return array<string, mixed>
     */
    public function build(?string $commodity = null): array
    {
        $today = Carbon::now();
        $target = $this->seasonOf($today);
        $commodity = $this->normalizeCommodityFilter($commodity);

        $harvest = $this->harvestAggregates();
        $planted = $this->plantedHectares();
        $riskByBarangay = $this->riskByBarangay($today);

        $rows = [];
        foreach ($planted as $key => $hectares) {
            [$barangay, $crop] = explode('||', $key, 2);
            if ($commodity && $crop !== $commodity) {
                continue;
            }
            if ($hectares <= 0) {
                continue;
            }

            $rate = $this->yieldRate(
                $harvest['barangay'][$barangay][$crop][$target['season']] ?? [],
                $harvest['municipal'][$crop][$target['season']] ?? [],
                $crop,
                $target['year']
            );
            $projectedKg = $hectares * $rate['kg_per_ha'];
            $last = $harvest['barangay'][$barangay][$crop][$target['season']][$target['year'] - 1] ?? null;
            $lastKg = $last ? (float) $last['kg'] : 0.0;
            $risk = $riskByBarangay[$barangay] ?? $this->emptyRisk();
            $buffer = (int) round($hectares * ($risk['risk_score'] / 100) * self::BUFFER_BAGS_PER_HA);

            $rows[] = [
                'barangay' => $barangay,
                'commodity' => $crop,
                'planted_ha' => round($hectares, 2),
                'projected_mt' => round($projectedKg / 1000, 2),
                'last_season_mt' => round($lastKg / 1000, 2),
                'has_last_season' => $lastKg > 0,
                'variance_mt' => round(($projectedKg - $lastKg) / 1000, 2),
                'yield_kg_per_ha' => round($rate['kg_per_ha'], 2),
                'yield_source' => $rate['source'],
                'confidence' => $rate['confidence'],
                'drought_score' => $risk['drought_score'],
                'typhoon_score' => $risk['typhoon_score'],
                'pest_score' => $risk['pest_score'],
                'risk_score' => $risk['risk_score'],
                'risk_level' => $risk['risk_level'],
                'drought_flag' => $risk['drought_flag'],
                'typhoon_flag' => $risk['typhoon_flag'],
                'buffer_bags' => $buffer,
            ];
        }

        usort($rows, function (array $a, array $b) {
            return $b['projected_mt'] <=> $a['projected_mt']
                ?: strcmp($a['barangay'], $b['barangay']);
        });

        $commodities = $this->commoditySeries($harvest['municipal'], $rows, $target, $commodity);

        $projectedMt = round(array_sum(array_column($rows, 'projected_mt')), 2);
        $lastMt = round(array_sum(array_column($rows, 'last_season_mt')), 2);
        $variance = round($projectedMt - $lastMt, 2);
        $highRisk = [];
        $bufferBags = 0;
        foreach ($rows as $row) {
            $bufferBags += $row['buffer_bags'];
            if ($row['risk_level'] === 'High') {
                $highRisk[$row['barangay']] = true;
            }
        }

        return [
            'summary' => [
                'season' => $target['season'],
                'year' => $target['year'],
                'projected_mt' => $projectedMt,
                'last_season_mt' => $lastMt,
                'surplus_shortfall_mt' => $variance,
                'direction' => $variance > 0.05 ? 'surplus' : ($variance < -0.05 ? 'shortfall' : 'balanced'),
                'high_risk_barangays' => count($highRisk),
                'suggested_buffer_bags' => $bufferBags,
                'generated_at' => $today->format('F j, Y g:i A'),
                'method_note' => 'Projected yield equals active planted hectares times a seasonal kilograms-per-hectare rate. '
                    .'The rate uses that barangay\'s harvest history for the same season, then the municipal seasonal average, '
                    .'then assumed rates (rice 4,500, corn 5,000, HVCC 3,500 kg/ha). '
                    .'When two or more past seasons exist, the rate is 70% pooled historical yield per hectare and 30% a least-squares trend. '
                    .'Surplus or shortfall compares the projection with the same season last year.',
                'formula' => 'buffer_bags = round(planted_ha × (risk_score / 100) × '.self::BUFFER_BAGS_PER_HA.'). '
                    .'risk_score = 0.45 × typhoon/flood exposure + 0.45 × drought vulnerability + 0.10 × active pest outbreaks.',
                'bags_per_at_risk_hectare' => self::BUFFER_BAGS_PER_HA,
            ],
            'commodities' => $commodities,
            'barangays' => $rows,
        ];
    }

    /**
     * @return array{barangay: array<string, array<string, array<string, array<int, array{kg: float, area: float}>>>>, municipal: array<string, array<string, array<int, array{kg: float, area: float}>>>}
     */
    private function harvestAggregates(): array
    {
        $barangay = [];
        $municipal = [];

        if (! Schema::hasTable('harvest_logs')) {
            return ['barangay' => $barangay, 'municipal' => $municipal];
        }

        $query = DB::table('harvest_logs')
            ->leftJoin('farm_plots', 'harvest_logs.farm_plot_id', '=', 'farm_plots.id')
            ->leftJoin('farmers', 'harvest_logs.farmer_id', '=', 'farmers.id')
            ->whereNull('harvest_logs.deleted_at');

        $columns = [
            'harvest_logs.crop_type',
            'harvest_logs.hvcc_commodity',
            'harvest_logs.total_yield',
            'harvest_logs.yield_unit',
            'harvest_logs.area_harvested',
            'harvest_logs.date_harvested',
            'harvest_logs.farm_location',
            'farm_plots.location_brgy',
            'farmers.permanent_brgy',
        ];
        if (Schema::hasColumn('farmers', 'farm_brgy')) {
            $columns[] = 'farmers.farm_brgy';
        }

        foreach ($query->get($columns) as $row) {
            $season = $this->seasonOf(Carbon::parse($row->date_harvested));
            $crop = $this->commodityBucket($row->crop_type, $row->hvcc_commodity ?? null);
            $name = $this->resolveBarangay(
                $row->farm_location,
                $row->location_brgy,
                $row->farm_brgy ?? null,
                $row->permanent_brgy
            );
            $kg = $this->yieldKg((float) $row->total_yield, $row->yield_unit);
            $area = max(0.0, (float) $row->area_harvested);
            if ($kg <= 0 || $area <= 0) {
                continue;
            }

            $this->addAggregate($barangay[$name][$crop][$season['season']][$season['year']], $kg, $area);
            $this->addAggregate($municipal[$crop][$season['season']][$season['year']], $kg, $area);
        }

        return ['barangay' => $barangay, 'municipal' => $municipal];
    }

    /**
     * @return array<string, float> keyed by "barangay||commodity"
     */
    private function plantedHectares(): array
    {
        $planted = [];
        if (! Schema::hasTable('planting_logs')) {
            return $planted;
        }

        $query = DB::table('planting_logs')
            ->leftJoin('farm_plots', 'planting_logs.farm_plot_id', '=', 'farm_plots.id')
            ->leftJoin('farmers', 'planting_logs.farmer_id', '=', 'farmers.id')
            ->whereNull('planting_logs.deleted_at');

        $columns = [
            'planting_logs.crop_type',
            'planting_logs.hvcc_commodity',
            'planting_logs.area_planted',
            'planting_logs.status',
            'planting_logs.farm_location',
            'farm_plots.location_brgy',
            'farmers.permanent_brgy',
        ];
        if (Schema::hasColumn('farmers', 'farm_brgy')) {
            $columns[] = 'farmers.farm_brgy';
        }
        if (! Schema::hasColumn('planting_logs', 'hvcc_commodity')) {
            $columns = array_values(array_filter($columns, fn ($c) => $c !== 'planting_logs.hvcc_commodity'));
        }
        if (! Schema::hasColumn('planting_logs', 'farm_location')) {
            $columns = array_values(array_filter($columns, fn ($c) => $c !== 'planting_logs.farm_location'));
        }
        if (! Schema::hasColumn('planting_logs', 'farm_plot_id')) {
            $query = DB::table('planting_logs')
                ->leftJoin('farmers', 'planting_logs.farmer_id', '=', 'farmers.id')
                ->whereNull('planting_logs.deleted_at');
            $columns = array_values(array_filter($columns, fn ($c) => $c !== 'farm_plots.location_brgy'));
        }

        foreach ($query->get($columns) as $row) {
            if (! $this->isActivePlanting($row->status ?? null)) {
                continue;
            }
            $hectares = (float) $row->area_planted;
            if ($hectares <= 0) {
                continue;
            }
            $crop = $this->commodityBucket($row->crop_type, $row->hvcc_commodity ?? null);
            $name = $this->resolveBarangay(
                $row->farm_location ?? null,
                $row->location_brgy ?? null,
                $row->farm_brgy ?? null,
                $row->permanent_brgy ?? null
            );
            $key = $name.'||'.$crop;
            $planted[$key] = ($planted[$key] ?? 0.0) + $hectares;
        }

        return $planted;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function riskByBarangay(Carbon $today): array
    {
        $map = [];

        if (Schema::hasTable('damage_assessments') && Schema::hasTable('farm_plots')) {
            $since = $today->copy()->subYears(3)->toDateString();
            $damages = DamageAssessment::query()
                ->join('farm_plots', 'damage_assessments.farm_plot_id', '=', 'farm_plots.id')
                ->whereDate('damage_assessments.date_of_calamity', '>=', $since)
                ->whereNull('damage_assessments.deleted_at')
                ->get([
                    'farm_plots.location_brgy',
                    'damage_assessments.calamity_type',
                    'damage_assessments.damage_percentage',
                ]);

            foreach ($damages as $damage) {
                $name = $this->resolveBarangay(null, $damage->location_brgy, null, null);
                $bucket = $map[$name] ?? $this->blankRiskBucket();
                $type = strtolower(trim((string) $damage->calamity_type));
                $pct = (float) $damage->damage_percentage;
                if (in_array($type, ['typhoon', 'flood'], true)) {
                    $bucket['typhoon_events']++;
                    $bucket['typhoon_damage_sum'] += $pct;
                } elseif ($type === 'drought') {
                    $bucket['drought_events']++;
                    $bucket['drought_damage_sum'] += $pct;
                }
                $map[$name] = $bucket;
            }
        }

        if (Schema::hasTable('tbl_weather_cache')) {
            $etColumn = Schema::hasColumn('tbl_weather_cache', 'evapotranspiration')
                ? 'evapotranspiration'
                : (Schema::hasColumn('tbl_weather_cache', 'et0_fao_evapotranspiration')
                    ? 'et0_fao_evapotranspiration'
                    : null);

            $select = [
                'barangay_name',
                DB::raw('MAX(precipitation_probability) as max_precip'),
            ];
            if ($etColumn) {
                $select[] = DB::raw("MAX({$etColumn}) as max_et0");
            }

            $weatherRows = WeatherCache::query()
                ->whereDate('forecast_date', '>=', $today->toDateString())
                ->whereDate('forecast_date', '<=', $today->copy()->addDays(3)->toDateString())
                ->select($select)
                ->groupBy('barangay_name')
                ->get();

            foreach ($weatherRows as $weather) {
                $name = $this->resolveBarangay($weather->barangay_name, null, null, null);
                $bucket = $map[$name] ?? $this->blankRiskBucket();
                $bucket['precip'] = max($bucket['precip'], (int) ($weather->max_precip ?? 0));
                $bucket['et0'] = max($bucket['et0'], (float) ($weather->max_et0 ?? 0));
                $map[$name] = $bucket;
            }
        }

        if (Schema::hasTable('pest_outbreaks') && Schema::hasTable('farm_plots')) {
            $pests = PestOutbreak::query()
                ->join('farm_plots', 'pest_outbreaks.farm_plot_id', '=', 'farm_plots.id')
                ->where('pest_outbreaks.status', 'Active')
                ->selectRaw('farm_plots.location_brgy as location_brgy, COUNT(*) as active_pests')
                ->groupBy('farm_plots.location_brgy')
                ->get();

            foreach ($pests as $pest) {
                $name = $this->resolveBarangay(null, $pest->location_brgy, null, null);
                $bucket = $map[$name] ?? $this->blankRiskBucket();
                $bucket['pests'] += (int) $pest->active_pests;
                $map[$name] = $bucket;
            }
        }

        $scored = [];
        foreach ($map as $name => $bucket) {
            $scored[$name] = $this->scoreRisk($bucket);
        }

        return $scored;
    }

    /**
     * @param  array<int, array{kg: float, area: float}>  $barangaySeasons
     * @param  array<int, array{kg: float, area: float}>  $municipalSeasons
     * @return array{kg_per_ha: float, source: string, confidence: string}
     */
    private function yieldRate(array $barangaySeasons, array $municipalSeasons, string $commodity, int $targetYear): array
    {
        $own = $this->projectRate($barangaySeasons, $targetYear);
        if ($own) {
            return [
                'kg_per_ha' => $own['kg_per_ha'],
                'source' => 'barangay_history',
                'confidence' => $this->confidence('barangay_history', $own),
            ];
        }

        $municipal = $this->projectRate($municipalSeasons, $targetYear);
        if ($municipal) {
            return [
                'kg_per_ha' => $municipal['kg_per_ha'],
                'source' => 'municipal_average',
                'confidence' => $this->confidence('municipal_average', $municipal),
            ];
        }

        return [
            'kg_per_ha' => $this->assumedKgPerHa($commodity),
            'source' => 'assumed',
            'confidence' => 'Assumed',
        ];
    }

    /**
     * @param  array<int, array{kg: float, area: float}>  $seasons
     * @return array{kg_per_ha: float, points: int, trended: bool, r2: float|null}|null
     */
    private function projectRate(array $seasons, int $targetYear): ?array
    {
        $points = [];
        $kg = 0.0;
        $area = 0.0;
        foreach ($seasons as $year => $agg) {
            $year = (int) $year;
            if ($year >= $targetYear || ($agg['area'] ?? 0) <= 0 || ($agg['kg'] ?? 0) <= 0) {
                continue;
            }
            $points[] = [$year, (float) $agg['kg'] / (float) $agg['area']];
            $kg += (float) $agg['kg'];
            $area += (float) $agg['area'];
        }

        if ($area <= 0 || $points === []) {
            return null;
        }

        $mean = $kg / $area;
        if (count($points) < 2) {
            return [
                'kg_per_ha' => $mean,
                'points' => count($points),
                'trended' => false,
                'r2' => null,
            ];
        }

        $reg = $this->linearRegression($points);
        if (! $reg) {
            return [
                'kg_per_ha' => $mean,
                'points' => count($points),
                'trended' => false,
                'r2' => null,
            ];
        }

        $nextYear = $targetYear;
        $trend = max(0.0, $reg['intercept'] + $reg['slope'] * $nextYear);

        return [
            'kg_per_ha' => (self::HISTORY_WEIGHT * $mean) + (self::TREND_WEIGHT * $trend),
            'points' => count($points),
            'trended' => true,
            'r2' => $reg['r2'],
        ];
    }

    /**
     * @param  array{points: int, trended: bool, r2: float|null}  $rate
     */
    private function confidence(string $source, array $rate): string
    {
        $r2 = $rate['r2'];
        if ($source === 'municipal_average') {
            if ($rate['trended'] && $r2 !== null && $r2 >= 0.4) {
                return 'Moderate';
            }

            return 'Low';
        }

        if (! $rate['trended'] || $r2 === null) {
            return 'Moderate';
        }
        if ($r2 >= 0.75) {
            return 'High';
        }
        if ($r2 >= 0.4) {
            return 'Moderate';
        }

        return 'Low';
    }

    /**
     * @param  array<string, array<string, array<int, array{kg: float, area: float}>>>  $municipal
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{season: string, year: int}  $target
     * @return array<int, array<string, mixed>>
     */
    private function commoditySeries(array $municipal, array $rows, array $target, ?string $commodity): array
    {
        $order = $commodity ? [$commodity] : ['Rice', 'Corn', 'HVCC'];
        $projected = [];
        $confidenceRank = ['Assumed' => 0, 'Low' => 1, 'Moderate' => 2, 'High' => 3];
        $confidence = [];

        foreach ($rows as $row) {
            $crop = $row['commodity'];
            $projected[$crop] = ($projected[$crop] ?? 0.0) + $row['projected_mt'];
            $rank = $confidenceRank[$row['confidence']] ?? 0;
            if (! isset($confidence[$crop]) || $rank < $confidence[$crop]['rank']) {
                $confidence[$crop] = ['rank' => $rank, 'label' => $row['confidence']];
            }
        }

        $series = [];
        foreach ($order as $crop) {
            $history = [];
            foreach ($municipal[$crop][$target['season']] ?? [] as $year => $agg) {
                $year = (int) $year;
                if ($year >= $target['year'] || ($agg['area'] ?? 0) <= 0) {
                    continue;
                }
                $history[] = [
                    'year' => $year,
                    'season' => $target['season'],
                    'total_yield_mt' => round(((float) $agg['kg']) / 1000, 2),
                    'area_ha' => round((float) $agg['area'], 2),
                ];
            }
            usort($history, fn ($a, $b) => $a['year'] <=> $b['year']);

            $hasProjection = array_key_exists($crop, $projected);
            if ($history === [] && ! $hasProjection) {
                continue;
            }

            $series[] = [
                'commodity' => $crop,
                'history' => $history,
                'projection' => [
                    'year' => $target['year'],
                    'season' => $target['season'],
                    'projected_mt' => round($projected[$crop] ?? 0, 2),
                    'confidence' => $confidence[$crop]['label'] ?? 'Assumed',
                ],
            ];
        }

        return $series;
    }

    /**
     * @param  array{typhoon_events: int, typhoon_damage_sum: float, drought_events: int, drought_damage_sum: float, precip: int, et0: float, pests: int}  $bucket
     * @return array<string, mixed>
     */
    private function scoreRisk(array $bucket): array
    {
        $typhoonHist = $bucket['typhoon_events'] > 0
            ? $bucket['typhoon_damage_sum'] / $bucket['typhoon_events']
            : 0.0;
        $droughtHist = $bucket['drought_events'] > 0
            ? $bucket['drought_damage_sum'] / $bucket['drought_events']
            : 0.0;
        $typhoonWeather = $bucket['precip'] >= 80 ? 100 : $bucket['precip'];
        $droughtWeather = $bucket['et0'] > 5
            ? 100
            : ($bucket['et0'] > 0 ? min(100, ($bucket['et0'] / 5) * 100) : 0);

        $typhoonScore = (int) min(100, round(0.7 * $typhoonHist + 0.3 * $typhoonWeather));
        $droughtScore = (int) min(100, round(0.7 * $droughtHist + 0.3 * $droughtWeather));
        $pestScore = (int) min(100, $bucket['pests'] * 20);
        $riskScore = (int) min(100, round(0.45 * $typhoonScore + 0.45 * $droughtScore + 0.10 * $pestScore));
        $level = $riskScore >= 66 ? 'High' : ($riskScore >= 33 ? 'Moderate' : 'Low');

        return [
            'drought_score' => $droughtScore,
            'typhoon_score' => $typhoonScore,
            'pest_score' => $pestScore,
            'risk_score' => $riskScore,
            'risk_level' => $level,
            'drought_flag' => $bucket['drought_events'] > 0 || $bucket['et0'] > 5 || $droughtScore >= 33,
            'typhoon_flag' => $bucket['typhoon_events'] > 0 || $bucket['precip'] >= 80 || $typhoonScore >= 33,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRisk(): array
    {
        return $this->scoreRisk($this->blankRiskBucket());
    }

    /**
     * @return array{typhoon_events: int, typhoon_damage_sum: float, drought_events: int, drought_damage_sum: float, precip: int, et0: float, pests: int}
     */
    private function blankRiskBucket(): array
    {
        return [
            'typhoon_events' => 0,
            'typhoon_damage_sum' => 0.0,
            'drought_events' => 0,
            'drought_damage_sum' => 0.0,
            'precip' => 0,
            'et0' => 0.0,
            'pests' => 0,
        ];
    }

    /**
     * @param  array{kg: float, area: float}|null  $slot
     */
    private function addAggregate(?array &$slot, float $kg, float $area): void
    {
        if (! is_array($slot)) {
            $slot = ['kg' => 0.0, 'area' => 0.0];
        }
        $slot['kg'] += $kg;
        $slot['area'] += $area;
    }

    /**
     * @return array{season: string, year: int}
     */
    private function seasonOf(Carbon $date): array
    {
        $month = (int) $date->month;
        if ($month >= 5 && $month <= 10) {
            return ['season' => 'Wet', 'year' => (int) $date->year];
        }
        if ($month >= 11) {
            return ['season' => 'Dry', 'year' => (int) $date->year];
        }

        return ['season' => 'Dry', 'year' => (int) $date->year - 1];
    }

    private function commodityBucket(?string $cropType, ?string $hvccCommodity): string
    {
        $normalized = strtolower(trim((string) $cropType));
        if (str_contains($normalized, 'rice')) {
            return 'Rice';
        }
        if (str_contains($normalized, 'corn')) {
            return 'Corn';
        }
        if ($hvccCommodity || $normalized !== '') {
            return 'HVCC';
        }

        return 'HVCC';
    }

    private function normalizeCommodityFilter(?string $commodity): ?string
    {
        $value = trim((string) $commodity);
        if (in_array($value, ['Rice', 'Corn', 'HVCC'], true)) {
            return $value;
        }

        return null;
    }

    private function assumedKgPerHa(string $commodity): float
    {
        return match ($commodity) {
            'Rice' => 4500.0,
            'Corn' => 5000.0,
            default => 3500.0,
        };
    }

    private function yieldKg(float $total, ?string $unit): float
    {
        $normalized = strtolower(trim((string) $unit));
        if (str_contains($normalized, 'ton')) {
            return $total * 1000;
        }

        return $total;
    }

    private function isActivePlanting(?string $status): bool
    {
        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            return true;
        }

        return ! in_array($normalized, ['not continued', 'harvested', 'completed', 'inactive'], true);
    }

    private function resolveBarangay(?string $farmLocation, ?string $plotBrgy, ?string $farmBrgy, ?string $permanentBrgy): string
    {
        foreach ([$farmLocation, $plotBrgy, $farmBrgy, $permanentBrgy] as $candidate) {
            $name = OfficialBarangays::normalizePsgcName((string) $candidate);
            if ($name !== '') {
                return $name;
            }
        }

        return 'Unassigned';
    }

    /**
     * @param  array<int, array{0: float|int, 1: float}>  $points
     * @return array{slope: float, intercept: float, r2: float}|null
     */
    private function linearRegression(array $points): ?array
    {
        $n = count($points);
        if ($n < 2) {
            return null;
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) {
            return null;
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        $meanY = $sumY / $n;
        $ssTot = $ssRes = 0.0;
        foreach ($points as [$x, $y]) {
            $pred = $intercept + $slope * $x;
            $ssRes += ($y - $pred) ** 2;
            $ssTot += ($y - $meanY) ** 2;
        }
        $r2 = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 1.0;

        return ['slope' => $slope, 'intercept' => $intercept, 'r2' => $r2];
    }
}
