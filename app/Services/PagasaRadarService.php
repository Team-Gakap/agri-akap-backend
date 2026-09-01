<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PagasaRadarService
{
    /** Philippines mosaic bounds (EPSG:4326) for GroundOverlay. */
    public const BOUNDS = [
        'north' => 21.5,
        'south' => 4.0,
        'east' => 127.5,
        'west' => 116.0,
    ];

    protected const CACHE_KEY = 'pagasa_radar_timeline';

    protected const CACHE_TTL_SECONDS = 600;

    /**
     * @return array{
     *   product: string,
     *   attribution: string,
     *   frames: array<int, array{observed_at: string, image_url: string, index: int}>,
     *   bounds: array{north: float, south: float, east: float, west: float},
     *   available: bool
     * }
     */
    public function timeline(string $product = 'mosaic-qpe'): array
    {
        if (! config('services.pagasa.radar_enabled', true)) {
            return $this->emptyPayload($product);
        }

        return Cache::remember(
            self::CACHE_KEY.':'.$product,
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchTimeline($product)
        );
    }

    /**
     * Rainfall estimate (mm/hr) at a coordinate from the latest radar frame.
     */
    public function pointValue(float $lat, float $lng, string $product = 'mosaic-qpe'): ?float
    {
        if (! config('services.pagasa.radar_enabled', true)) {
            return null;
        }

        $timeline = $this->timeline($product);
        if (! $timeline['available'] || $timeline['frames'] === []) {
            return null;
        }

        $latest = $timeline['frames'][array_key_last($timeline['frames'])];
        $baseUrl = rtrim((string) config('services.pagasa.panahon_base_url'), '/');

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get($baseUrl.'/api/v1/radar-image/point', [
                    'sublayer' => $product,
                    'lon' => $lng,
                    'lat' => $lat,
                    'v' => 1,
                    'index' => $latest['index'],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $values = $response->json('values');
            if (! is_array($values) || ! isset($values[0])) {
                return null;
            }

            $value = (float) $values[0];
            if ($value >= 9999) {
                return null;
            }

            return round($value, 1);
        } catch (Throwable $e) {
            Log::warning('PAGASA radar point query failed', [
                'lat' => $lat,
                'lng' => $lng,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *   product: string,
     *   attribution: string,
     *   frames: array<int, array{observed_at: string, image_url: string, index: int}>,
     *   bounds: array{north: float, south: float, east: float, west: float},
     *   available: bool
     * }
     */
    protected function fetchTimeline(string $product): array
    {
        $baseUrl = rtrim((string) config('services.pagasa.panahon_base_url'), '/');

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($baseUrl.'/api/v1/radar/timeline', [
                    'sublayer' => $product,
                ]);

            if (! $response->successful()) {
                Log::warning('PAGASA radar timeline HTTP error', ['status' => $response->status()]);

                return $this->emptyPayload($product);
            }

            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
            $imageUrls = $data['image_urls'] ?? $data['images'] ?? [];
            $obsDates = $data['observation_dates'] ?? $data['timestamps'] ?? [];

            if (! is_array($imageUrls) || $imageUrls === []) {
                return $this->emptyPayload($product);
            }

            $frames = [];
            foreach (array_values($imageUrls) as $index => $url) {
                if (! is_string($url) || trim($url) === '') {
                    continue;
                }

                $observedAt = null;
                if (is_array($obsDates)) {
                    $observedAt = $obsDates[(string) $index] ?? $obsDates[$index] ?? null;
                }

                $frames[] = [
                    'observed_at' => is_string($observedAt) ? $observedAt : '',
                    'image_url' => $this->resolveImageUrl($url, $baseUrl),
                    'index' => $index,
                ];
            }

            if ($frames === []) {
                return $this->emptyPayload($product);
            }

            return [
                'product' => $product,
                'attribution' => 'PAGASA / DOST via Panahon',
                'frames' => array_slice($frames, -6),
                'bounds' => self::BOUNDS,
                'available' => true,
            ];
        } catch (Throwable $e) {
            Log::warning('PAGASA radar timeline fetch failed', ['message' => $e->getMessage()]);

            return $this->emptyPayload($product);
        }
    }

    protected function resolveImageUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $baseUrl.'/'.ltrim($url, '/');
    }

    /**
     * @return array{
     *   product: string,
     *   attribution: string,
     *   frames: array<int, array{observed_at: string, image_url: string, index: int}>,
     *   bounds: array{north: float, south: float, east: float, west: float},
     *   available: bool
     * }
     */
    protected function emptyPayload(string $product): array
    {
        return [
            'product' => $product,
            'attribution' => 'PAGASA / DOST via Panahon',
            'frames' => [],
            'bounds' => self::BOUNDS,
            'available' => false,
        ];
    }
}
