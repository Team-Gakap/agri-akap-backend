<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\WeatherHistorical;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WeatherHistoricalService
{
    public const TIMEZONE = WeatherService::TIMEZONE;

    /** Past-days multi-location payloads are large; keep chunks small to avoid timeouts. */
    public const CHUNK_SIZE = 8;

    public const PAST_DAYS = 30;

    protected const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    protected const HTTP_TIMEOUT_SECONDS = 180;

    protected const CONNECT_TIMEOUT_SECONDS = 20;

    protected const MAX_ATTEMPTS = 3;

    /**
     * Bulk-fetch Open-Meteo past 30 days (+ today) for every active barangay
     * and mass-upsert into tbl_weather_historical.
     *
     * @return array{synced:int, barangays:int, chunks:int}
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

        foreach ($barangays->chunk(self::CHUNK_SIZE) as $chunk) {
            $chunks++;
            $synced += $this->fetchChunk($chunk);
        }

        $this->pruneStaleWindow();

        return [
            'synced' => $synced,
            'barangays' => $barangays->count(),
            'chunks' => $chunks,
        ];
    }

    /**
     * @param  Collection<int, Barangay>  $chunk
     */
    protected function fetchChunk(Collection $chunk): int
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
                        'daily' => 'precipitation_sum,temperature_2m_max,et0_fao_evapotranspiration',
                        'timezone' => self::TIMEZONE,
                        'past_days' => self::PAST_DAYS,
                        'forecast_days' => 1,
                    ]);

                if ($response->successful()) {
                    break;
                }

                $lastError = 'HTTP '.$response->status();
                Log::warning('Open-Meteo historical attempt failed', [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'count' => $chunk->count(),
                ]);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('Open-Meteo historical connection attempt failed', [
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
            Log::error('Open-Meteo historical bulk fetch failed', [
                'error' => $lastError,
                'count' => $chunk->count(),
            ]);
            throw new RuntimeException('Unable to fetch Open-Meteo historical forecast ('.$lastError.').');
        }

        $payload = $response->json();
        $locations = $this->normalizeLocations($payload);

        if (count($locations) !== $chunk->count()) {
            Log::warning('Open-Meteo historical location count mismatch', [
                'expected' => $chunk->count(),
                'received' => count($locations),
            ]);
        }

        $rows = [];
        $now = Carbon::now(self::TIMEZONE)->toDateTimeString();
        $barangayList = $chunk->values();

        foreach ($barangayList as $index => $barangay) {
            $location = $locations[$index] ?? null;
            if (! is_array($location)) {
                continue;
            }

            foreach ($this->buildRowsForLocation($barangay->name, $location, $now) as $row) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return 0;
        }

        // Mass upsert — skips model events, so UUIDs are generated above.
        WeatherHistorical::upsert(
            $rows,
            ['barangay_name', 'date'],
            [
                'precipitation_sum',
                'temperature_max',
                'et0_fao_evapotranspiration',
                'updated_at',
            ]
        );

        return count($rows);
    }

    /**
     * @param  array<string,mixed>  $location
     * @return list<array<string,mixed>>
     */
    protected function buildRowsForLocation(string $barangayName, array $location, string $now): array
    {
        $daily = $location['daily'] ?? null;
        if (! is_array($daily) || empty($daily['time']) || ! is_array($daily['time'])) {
            return [];
        }

        $precip = $daily['precipitation_sum'] ?? [];
        $tempMax = $daily['temperature_2m_max'] ?? [];
        $et0 = $daily['et0_fao_evapotranspiration'] ?? [];

        $rows = [];

        foreach ($daily['time'] as $index => $date) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'barangay_name' => $barangayName,
                'date' => $date,
                'precipitation_sum' => isset($precip[$index]) ? round((float) $precip[$index], 2) : null,
                'temperature_max' => isset($tempMax[$index]) ? round((float) $tempMax[$index], 2) : null,
                'et0_fao_evapotranspiration' => isset($et0[$index]) ? round((float) $et0[$index], 3) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
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

        if (isset($payload['daily'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * Keep a rolling ~30-day archive (drop anything older than past_days).
     */
    protected function pruneStaleWindow(): void
    {
        $cutoff = Carbon::now(self::TIMEZONE)->subDays(self::PAST_DAYS)->toDateString();

        WeatherHistorical::query()
            ->where('date', '<', $cutoff)
            ->delete();
    }
}
