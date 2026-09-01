<?php

namespace App\Console\Commands;

use App\Services\WeatherHistoricalService;
use App\Services\WeatherHourlyService;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Throwable;

class SyncAllWeatherCommand extends Command
{
    protected $signature = 'weather:sync-all
                            {--skip-daily : Skip the 7-day daily forecast cache}
                            {--skip-hourly : Skip the hourly short-term cache}
                            {--skip-historical : Skip the 30-day historical archive}';

    protected $description = 'Sequentially sync Open-Meteo daily, hourly, and historical climate caches for all Echague barangays';

    public function handle(
        WeatherService $dailyService,
        WeatherHourlyService $hourlyService,
        WeatherHistoricalService $historicalService,
    ): int {
        $failed = false;

        if (! $this->option('skip-daily')) {
            $this->info('① Daily forecast cache…');
            try {
                $result = $dailyService->fetchAndCache();
                $this->info("   Synced {$result['synced']} row(s) · {$result['barangays']} barangay(s) · {$result['chunks']} chunk(s).");
            } catch (Throwable $e) {
                $this->error('   Daily sync failed: '.$e->getMessage());
                $failed = true;
            }
        }

        if (! $this->option('skip-hourly')) {
            $this->info('② Hourly short-term cache…');
            try {
                $result = $hourlyService->fetchAndCache();
                $this->info("   Synced {$result['synced']} row(s) · {$result['barangays']} barangay(s) · {$result['chunks']} chunk(s).");
                if (($result['failed_chunks'] ?? 0) > 0) {
                    $this->warn("   {$result['failed_chunks']} chunk(s) failed after retries.");
                    if ($result['synced'] === 0) {
                        $failed = true;
                    }
                }
            } catch (Throwable $e) {
                $this->error('   Hourly sync failed: '.$e->getMessage());
                $failed = true;
            }
        }

        if (! $this->option('skip-historical')) {
            $this->info('③ Historical 30-day archive…');
            try {
                $result = $historicalService->fetchAndCache();
                $this->info("   Synced {$result['synced']} row(s) · {$result['barangays']} barangay(s) · {$result['chunks']} chunk(s).");
            } catch (Throwable $e) {
                $this->error('   Historical sync failed: '.$e->getMessage());
                $failed = true;
            }
        }

        if ($failed) {
            $this->warn('weather:sync-all finished with one or more failures.');

            return self::FAILURE;
        }

        $this->info('All requested weather caches are up to date.');

        return self::SUCCESS;
    }
}
