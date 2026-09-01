<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BagyoAdvisoryService
{
    protected const CACHE_KEY = 'bagyo_national_advisories';

    protected const CACHE_TTL_SECONDS = 900;

    /** Region II / Isabela relevance keywords. */
    protected const REGION_KEYWORDS = [
        'isabela',
        'echague',
        'cagayan valley',
        'region ii',
        'region 2',
        'northern luzon',
        'luzon',
    ];

    /**
     * @return array{
     *   source: string,
     *   attribution: string,
     *   rainfall_advisories: array<int, array<string, mixed>>,
     *   cyclone_bulletins: array<int, array<string, mixed>>,
     *   available: bool
     * }
     */
    public function nationalAdvisories(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchAdvisories()
        );
    }

    /**
     * @return array{
     *   source: string,
     *   attribution: string,
     *   rainfall_advisories: array<int, array<string, mixed>>,
     *   cyclone_bulletins: array<int, array<string, mixed>>,
     *   available: bool
     * }
     */
    protected function fetchAdvisories(): array
    {
        $baseUrl = rtrim((string) config('services.bagyo.base_url'), '/');

        $rainfall = $this->fetchJson($baseUrl.'/v1/rainfall/current');
        $bulletin = $this->fetchJson($baseUrl.'/v1/bulletins/latest');

        $rainfallItems = $this->normalizeList($rainfall, 'data');
        $bulletinItems = $this->normalizeBulletins($bulletin);

        $scopedRainfall = $this->filterRelevant($rainfallItems);
        $scopedBulletins = $this->filterRelevant($bulletinItems);

        return [
            'source' => 'Bagyo API',
            'attribution' => 'Third-party PAGASA bulletin aggregator (not official PAGASA)',
            'rainfall_advisories' => $scopedRainfall !== [] ? $scopedRainfall : $rainfallItems,
            'cyclone_bulletins' => $scopedBulletins !== [] ? $scopedBulletins : $bulletinItems,
            'available' => $rainfallItems !== [] || $bulletinItems !== [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchJson(string $url): ?array
    {
        try {
            $response = Http::timeout(12)->acceptJson()->get($url);
            if (! $response->successful()) {
                Log::warning('Bagyo API HTTP error', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (Throwable $e) {
            Log::warning('Bagyo API fetch failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeList(?array $payload, string $key): array
    {
        if ($payload === null) {
            return [];
        }

        $items = $payload[$key] ?? $payload['advisories'] ?? $payload;
        if (! is_array($items)) {
            return [];
        }

        if (array_is_list($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        return [$items];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeBulletins(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $bulletin = $payload['data'] ?? $payload['bulletin'] ?? $payload;
        if (! is_array($bulletin)) {
            return [];
        }

        if (isset($bulletin['id']) || isset($bulletin['title']) || isset($bulletin['cyclone_name'])) {
            return [$bulletin];
        }

        if (array_is_list($bulletin)) {
            return array_values(array_filter($bulletin, 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function filterRelevant(array $items): array
    {
        $matched = [];

        foreach ($items as $item) {
            $haystack = mb_strtolower(json_encode($item, JSON_UNESCAPED_UNICODE) ?: '');
            foreach (self::REGION_KEYWORDS as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $matched[] = $item;
                    break;
                }
            }
        }

        return $matched;
    }
}
