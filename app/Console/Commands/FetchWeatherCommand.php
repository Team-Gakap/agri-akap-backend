<?php

namespace App\Console\Commands;

use App\Services\WeatherHourlyService;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Throwable;

class FetchWeatherCommand extends Command
{
    protected $signature = 'weather:fetch {--daily : Fetch daily cache only} {--hourly : Fetch hourly cache only}';

    protected $description = 'Bulk-fetch Open-Meteo hyper-local daily + hourly forecasts for all Echague barangays';

    public function handle(WeatherService $weatherService, WeatherHourlyService $hourlyService): int
    {
        $dailyOnly = (bool) $this->option('daily');
        $hourlyOnly = (bool) $this->option('hourly');
        $runDaily = ! $hourlyOnly;
        $runHourly = ! $dailyOnly;

        if ($runDaily) {
            $this->info('Bulk-fetching Open-Meteo daily forecasts (chunks of '.WeatherService::CHUNK_SIZE.')…');

            try {
                $result = $weatherService->fetchAndCache();
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Synced {$result['synced']} daily forecast row(s) across {$result['barangays']} barangay(s) in {$result['chunks']} chunk(s).");
            foreach ($result['dates'] as $date) {
                $this->line("  • {$date}");
            }
        }

        if ($runHourly) {
            $this->info('Bulk-fetching Open-Meteo hourly forecasts (next 48h, chunks of '.WeatherHourlyService::CHUNK_SIZE.')…');

            try {
                $hourly = $hourlyService->fetchAndCache();
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Synced {$hourly['synced']} hourly forecast row(s) across {$hourly['barangays']} barangay(s) in {$hourly['chunks']} chunk(s).");
            if (($hourly['failed_chunks'] ?? 0) > 0) {
                $this->warn("{$hourly['failed_chunks']} hourly chunk(s) failed after retries.");
                if ($hourly['synced'] === 0) {
                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
