<?php

namespace App\Services;

use App\Models\PestMonitoring;
use App\Models\PlantingLog;
use App\Models\StandingCropLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class CropStageService
{
    public const STAGE_SEEDLING = 'Seedling';

    public const STAGE_VEGETATIVE = 'Vegetative';

    public const STAGE_REPRODUCTIVE = 'Reproductive';

    public const STAGE_MATURITY = 'Maturity';

    public const STAGE_HARVEST_READY = 'Harvest Ready';

    public const BUCKET_LABELS = [
        'seedling' => self::STAGE_SEEDLING,
        'vegetative' => self::STAGE_VEGETATIVE,
        'reproductive' => self::STAGE_REPRODUCTIVE,
        'maturity' => self::STAGE_MATURITY,
    ];

    /**
     * Resolve the live growth stage for a crop planting.
     *
     * @return array{
     *     current_stage: string,
     *     days_elapsed: int,
     *     days_to_harvest: int,
     *     estimated_harvest_date: string,
     *     classification: string,
     *     classification_label: string
     * }|null
     */
    public function resolveStage(string $commodity, ?string $variety, Carbon|string|null $datePlanted): ?array
    {
        $cropKey = $this->normalizeCommodity($commodity);
        if ($cropKey === null || $datePlanted === null || $datePlanted === '') {
            return null;
        }

        $classKey = $this->matchClassification($cropKey, $variety);
        $class = config("crop_lifecycles.crops.{$cropKey}.classifications.{$classKey}");
        if (! is_array($class) || empty($class['stages'])) {
            return null;
        }

        $tz = WeatherService::TIMEZONE;
        $planted = Carbon::parse($datePlanted, $tz)->startOfDay();
        $today = Carbon::now($tz)->startOfDay();
        $daysElapsed = $planted->greaterThan($today)
            ? 0
            : (int) round($planted->diffInDays($today));

        $maturityMax = (int) ($class['total_maturity_days'] ?? $class['stages']['maturity'][1] ?? 0);
        $harvestDate = $planted->copy()->addDays($maturityMax);
        $daysToHarvest = max(0, $maturityMax - $daysElapsed);

        return [
            'current_stage' => $this->stageForDays($class['stages'], $daysElapsed),
            'days_elapsed' => $daysElapsed,
            'days_to_harvest' => $daysToHarvest,
            'estimated_harvest_date' => $harvestDate->toDateString(),
            'classification' => $classKey,
            'classification_label' => (string) ($class['label'] ?? $classKey),
        ];
    }

    public function resolveForPlantingLog(PlantingLog $log): ?array
    {
        return $this->resolveStage(
            (string) $log->crop_type,
            $log->variety,
            $log->date_planted,
        );
    }

    /**
     * Live stage buckets from active Rice/Corn planting logs.
     *
     * @return array{
     *     by_plot: array<string, string>,
     *     by_farmer: array<string, string>,
     *     tally: array{seedling: int, vegetative: int, reproductive: int, maturity: int}
     * }
     */
    public function liveStageBuckets(?string $barangay = null): array
    {
        $empty = [
            'by_plot' => [],
            'by_farmer' => [],
            'tally' => [
                'seedling' => 0,
                'vegetative' => 0,
                'reproductive' => 0,
                'maturity' => 0,
            ],
        ];

        if (! Schema::hasTable('planting_logs')) {
            return $empty;
        }

        $query = PlantingLog::query()
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereIn('crop_type', ['Rice', 'Corn']);

        if ($barangay) {
            $query->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay));
        }

        $logs = $query
            ->orderByDesc('date_planted')
            ->orderByDesc('created_at')
            ->get(['id', 'farmer_id', 'farm_plot_id', 'crop_type', 'variety', 'date_planted']);

        $byPlot = [];
        $byFarmer = [];
        $tally = $empty['tally'];

        foreach ($logs as $log) {
            $resolved = $this->resolveForPlantingLog($log);
            $bucket = $this->bucketKey($resolved['current_stage'] ?? null);
            if ($bucket === null) {
                continue;
            }

            $tally[$bucket]++;

            $farmerId = (string) $log->farmer_id;
            if ($farmerId !== '' && ! isset($byFarmer[$farmerId])) {
                $byFarmer[$farmerId] = $bucket;
            }

            $plotId = (string) ($log->farm_plot_id ?? '');
            if ($plotId !== '' && ! isset($byPlot[$plotId])) {
                $byPlot[$plotId] = $bucket;
            }
        }

        return [
            'by_plot' => $byPlot,
            'by_farmer' => $byFarmer,
            'tally' => $tally,
        ];
    }

    /**
     * Hybrid dashboard tally: live planting stages first, then standing-crop
     * (and pest-monitoring) records whose plot/farmer is not already covered.
     *
     * @return array{seedling: int, vegetative: int, reproductive: int, maturity: int}
     */
    public function hybridStageTally(?string $barangay = null): array
    {
        $live = $this->liveStageBuckets($barangay);
        $counts = $live['tally'];

        if (Schema::hasTable('standing_crop_logs')) {
            $query = StandingCropLog::query()->select(['farm_plot_id', 'farmer_id', 'growth_stage']);
            if ($barangay) {
                $query->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay));
            }
            foreach ($query->get() as $row) {
                if ($this->isCoveredByLive($row->farm_plot_id, $row->farmer_id, $live)) {
                    continue;
                }
                $this->incrementBucket($counts, (string) $row->growth_stage);
            }
        }

        if (
            array_sum($counts) === 0
            && Schema::hasTable('pest_monitoring')
            && Schema::hasColumn('pest_monitoring', 'crop_stage')
        ) {
            $query = PestMonitoring::query();
            if ($barangay) {
                $query->whereHas('farmer', fn ($farmer) => $farmer->where('permanent_brgy', $barangay));
            }
            foreach ($query->pluck('crop_stage') as $stage) {
                $this->incrementBucket($counts, (string) $stage);
            }
        }

        return $counts;
    }

    public function bucketKey(?string $stage): ?string
    {
        $raw = strtolower(trim((string) $stage));
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, 'harvest')) {
            return 'maturity';
        }
        if (str_contains($raw, 'seed')) {
            return 'seedling';
        }
        if (str_contains($raw, 'veget')) {
            return 'vegetative';
        }
        if (str_contains($raw, 'reprod')) {
            return 'reproductive';
        }
        if (str_contains($raw, 'matur')) {
            return 'maturity';
        }

        return null;
    }

    public function bucketLabel(string $bucket): string
    {
        return self::BUCKET_LABELS[$bucket] ?? ucfirst($bucket);
    }

    public function normalizeCommodity(string $commodity): ?string
    {
        $raw = strtolower(trim($commodity));
        if ($raw === '') {
            return null;
        }

        foreach (config('crop_lifecycles.crops', []) as $key => $crop) {
            $aliases = $crop['aliases'] ?? [$key];
            foreach ($aliases as $alias) {
                if ($raw === $alias || str_contains($raw, $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function matchClassification(string $cropKey, ?string $variety): string
    {
        $classes = config("crop_lifecycles.crops.{$cropKey}.classifications", []);
        $normalized = $this->normalizeVariety((string) $variety);

        if ($normalized !== '') {
            foreach ($classes as $key => $class) {
                if ($key === 'default') {
                    continue;
                }
                foreach ($class['varieties'] ?? [] as $name) {
                    if ($this->normalizeVariety((string) $name) === $normalized) {
                        return $key;
                    }
                }
            }

            $keywords = config("crop_lifecycles.keywords.{$cropKey}", []);
            foreach ($keywords as $classKey => $tokens) {
                foreach ($tokens as $token) {
                    if ($token !== '' && str_contains($normalized, strtolower((string) $token))) {
                        return $classKey;
                    }
                }
            }
        }

        return isset($classes['default']) ? 'default' : (string) array_key_first($classes);
    }

    private function normalizeVariety(string $variety): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($variety)) ?? '');
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $stages
     */
    private function stageForDays(array $stages, int $daysElapsed): string
    {
        $order = ['seedling', 'vegetative', 'reproductive', 'maturity'];
        foreach ($order as $key) {
            $range = $stages[$key] ?? null;
            if (! is_array($range) || count($range) < 2) {
                continue;
            }
            if ($daysElapsed >= (int) $range[0] && $daysElapsed <= (int) $range[1]) {
                return self::BUCKET_LABELS[$key];
            }
        }

        $maturityMax = (int) ($stages['maturity'][1] ?? 0);
        if ($daysElapsed > $maturityMax) {
            return self::STAGE_HARVEST_READY;
        }

        return self::STAGE_SEEDLING;
    }

    /**
     * @param  array{by_plot: array<string, string>, by_farmer: array<string, string>}  $live
     */
    private function isCoveredByLive(mixed $farmPlotId, mixed $farmerId, array $live): bool
    {
        $plotId = (string) ($farmPlotId ?? '');
        if ($plotId !== '' && isset($live['by_plot'][$plotId])) {
            return true;
        }

        if ($plotId === '') {
            $id = (string) ($farmerId ?? '');

            return $id !== '' && isset($live['by_farmer'][$id]);
        }

        return false;
    }

    /**
     * @param  array{seedling: int, vegetative: int, reproductive: int, maturity: int}  $counts
     */
    private function incrementBucket(array &$counts, string $raw): void
    {
        $key = $this->bucketKey($raw);
        if ($key !== null) {
            $counts[$key]++;
        }
    }
}
