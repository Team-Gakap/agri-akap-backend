<?php

namespace App\Http\Controllers;

use App\Support\OfficialBarangays;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PsgcController extends Controller
{
    private const BASE = 'https://psgc.cloud/api';

    private const CACHE_TTL = 86400;

    private const ECHAGUE_REGION_CODE = '0200000000';

    private const ECHAGUE_PROVINCE_CODE = '0203100000';

    private const ECHAGUE_CITY_CODE = '0203112000';

    public function regions(): JsonResponse
    {
        return $this->proxy('/regions');
    }

    public function provinces(string $code): JsonResponse
    {
        return $this->proxy("/regions/{$code}/provinces");
    }

    public function cities(string $code): JsonResponse
    {
        return $this->proxy("/provinces/{$code}/cities-municipalities");
    }

    public function barangays(string $code): JsonResponse
    {
        try {
            $rows = $this->fetch("/cities-municipalities/{$code}/barangays");
            if ($code === self::ECHAGUE_CITY_CODE) {
                $rows = array_map(function (array $row) {
                    return [
                        'code' => $row['code'] ?? '',
                        'name' => OfficialBarangays::normalizePsgcName((string) ($row['name'] ?? '')),
                    ];
                }, $rows);
            }

            return response()->json(['status' => 'success', 'data' => $rows]);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'PSGC barangay lookup unavailable.',
            ], 503);
        }
    }

    public function echagueDefaults(): JsonResponse
    {
        $data = Cache::remember('psgc.echague.defaults', self::CACHE_TTL, function () {
            $barangays = OfficialBarangays::names();

            try {
                $rows = $this->fetch('/cities-municipalities/'.self::ECHAGUE_CITY_CODE.'/barangays');
                $fromApi = collect($rows)
                    ->pluck('name')
                    ->map(fn ($name) => OfficialBarangays::normalizePsgcName((string) $name))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($fromApi !== []) {
                    $barangays = $fromApi;
                }
            } catch (\Throwable) {
                // Keep tbl_barangays / seeder fallback.
            }

            return [
                'region' => 'Region II',
                'region_code' => self::ECHAGUE_REGION_CODE,
                'province' => 'Isabela',
                'province_code' => self::ECHAGUE_PROVINCE_CODE,
                'city' => 'Echague',
                'city_code' => self::ECHAGUE_CITY_CODE,
                'barangays' => $barangays,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    private function proxy(string $path): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->fetch($path),
            ]);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'PSGC lookup unavailable.',
            ], 503);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $path): array
    {
        return Cache::remember('psgc.'.md5($path), self::CACHE_TTL, function () use ($path) {
            $response = Http::timeout(15)->get(self::BASE.$path);
            $response->throw();

            $json = $response->json();

            return is_array($json) ? $json : [];
        });
    }
}
