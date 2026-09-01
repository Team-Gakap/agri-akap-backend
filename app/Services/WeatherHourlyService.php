<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\WeatherCurrent;
use App\Models\WeatherHourly;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WeatherHourlyService
{
    public const TIMEZONE = WeatherService::TIMEZONE;

    /** Smaller than daily chunks — hourly payloads are ~48 timesteps × 4 vars per location. */
    public const CHUNK_SIZE = 8;

    protected const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    protected const HTTP_TIMEOUT_SECONDS = 120;

    protected const CONNECT_TIMEOUT_SECONDS = 20;

    protected const MAX_ATTEMPTS = 3;

    /**
     * Bulk-fetch Open-Meteo hourly forecasts for every active barangay and upsert
     * the next ~48 hours into tbl_weather_hourly (enough for a rolling 24h window).
     *
     * @return array{synced:int, barangays:int, chunks:int, failed_chunks:int}
     */
    public function fetchAndCache(): array
    {
        $barangays = Barangay::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'latitude', 'longitude']);

        if ($barangays->isEmpty()) {
            throw new RuntimeException('No barangays found. Run: php artisan db:seed --class=BarangayCoordinateSeeder');
        }

        $synced = 0;
        $chunks = 0;
        $failedChunks = 0;

        foreach ($barangays->chunk(self::CHUNK_SIZE) as $chunk) {
            $chunks++;
            $rows = $this->fetchChunk($chunk);
            if ($rows === null) {
                $failedChunks++;
                continue;
            }
            $synced += $rows;
        }

        $this->pruneStaleWindow();

        return [
            'synced' => $synced,
            'barangays' => $barangays->count(),
            'chunks' => $chunks,
            'failed_chunks' => $failedChunks,
        ];
    }

    /**
     * @param  Collection<int, Barangay>  $chunk
     * @return int|null  Synced row count, or null if the chunk failed after retries
     */
    protected function fetchChunk(Collection $chunk): ?int
    {
        $lats = $chunk->pluck('latitude')->map(fn ($v) => (string) $v)->implode(',');
        $lngs = $chunk->pluck('longitude')->map(fn ($v) => (string) $v)->implode(',');

        $response = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get(self::FORECAST_URL, [
                        'latitude' => $lats,
                        'longitude' => $lngs,
                        'hourly' => 'temperature_2m,precipitation_probability,windspeed_10m,weathercode',
                        'current' => 'temperature_2m,precipitation,rain,precipitation_probability,weathercode,windspeed_10m',
                        'timezone' => self::TIMEZONE,
                        'forecast_days' => 2,
                    ]);

                if ($response->successful()) {
                    break;
                }

                $lastError = 'HTTP '.$response->status();
                Log::warning('Open-Meteo hourly attempt failed', [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'count' => $chunk->count(),
                ]);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('Open-Meteo hourly connection attempt failed', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                    'count' => $chunk->count(),
                ]);
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(500_000 * $attempt);
            }
        }

        if ($response === null || ! $response->successful()) {
            Log::error('Open-Meteo hourly bulk fetch failed', [
                'error' => $lastError,
                'count' => $chunk->count(),
            ]);

            return null;
        }

        $payload = $response->json();
        $locations = $this->normalizeLocations($payload);

        if (count($locations) !== $chunk->count()) {
            Log::warning('Open-Meteo hourly location count mismatch', [
                'expected' => $chunk->count(),
                'received' => count($locations),
            ]);
        }

        $synced = 0;
        $barangayList = $chunk->values();

        foreach ($barangayList as $index => $barangay) {
            $location = $locations[$index] ?? null;
            if (! is_array($location)) {
                continue;
            }

            $synced += $this->upsertLocationHourly($barangay->name, $location);
            $this->upsertLocationCurrent($barangay->name, $location);
        }

        return $synced;
    }

    /**
     * @param  array<string,mixed>  $location
     */
    protected function upsertLocationCurrent(string $barangayName, array $location): void
    {
        $current = $location['current'] ?? null;
        if (! is_array($current)) {
            return;
        }

        $time = $current['time'] ?? null;
        if (! is_string($time) || trim($time) === '') {
            $time = Carbon::now(self::TIMEZONE)->toIso8601String();
        }

        WeatherCurrent::updateOrCreate(
            ['barangay_name' => $barangayName],
            [
                'observed_at' => Carbon::parse($time, self::TIMEZONE),
                'temperature' => $current['temperature_2m'] ?? null,
                'precipitation' => $current['precipitation'] ?? null,
                'rain' => $current['rain'] ?? null,
                'precipitation_probability' => isset($current['precipitation_probability'])
                    ? (int) round((float) $current['precipitation_probability'])
                    : null,
                'wind_speed' => $current['windspeed_10m']
                    ?? $current['wind_speed_10m']
                    ?? null,
                'weather_code' => isset($current['weathercode'])
                    ? (int) $current['weathercode']
                    : (isset($current['weather_code']) ? (int) $current['weather_code'] : null),
            ]
        );
    }

    /**
     * @param  array<string,mixed>|list<array<string,mixed>>  $payload
     * @return list<array<string,mixed>>
     */
    protected function normalizeLocations(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['hourly'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $location
     */
    protected function upsertLocationHourly(string $barangayName, array $location): int
    {
        $hourly = $location['hourly'] ?? null;
        if (! is_array($hourly) || empty($hourly['time']) || ! is_array($hourly['time'])) {
            return 0;
        }

        $temps = $hourly['temperature_2m'] ?? [];
        $rain = $hourly['precipitation_probability'] ?? [];
        $winds = $hourly['windspeed_10m'] ?? $hourly['wind_speed_10m'] ?? [];
        $codes = $hourly['weathercode'] ?? $hourly['weather_code'] ?? [];

        $synced = 0;

        foreach ($hourly['time'] as $index => $timestamp) {
            $forecastDatetime = Carbon::parse($timestamp, self::TIMEZONE);

            WeatherHourly::updateOrCreate(
                [
                    'barangay_name' => $barangayName,
                    'forecast_datetime' => $forecastDatetime,
                ],
                [
                    'temperature' => isset($temps[$index]) ? (float) $temps[$index] : null,
                    'precipitation_probability' => isset($rain[$index])
                        ? (int) round((float) $rain[$index])
                        : null,
                    'wind_speed' => isset($winds[$index]) ? (float) $winds[$index] : null,
                    'weather_code' => isset($codes[$index]) ? (int) $codes[$index] : null,
                ]
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * Drop hours older than "now" and anything beyond the 48-hour fetch window.
     */
    protected function pruneStaleWindow(): void
    {
        $now = Carbon::now(self::TIMEZONE);
        $windowEnd = $now->copy()->addDays(2)->endOfDay();

        WeatherHourly::query()
            ->where(function ($query) use ($now, $windowEnd) {
                $query->where('forecast_datetime', '<', $now)
                    ->orWhere('forecast_datetime', '>', $windowEnd);
            })
            ->delete();
    }
}
