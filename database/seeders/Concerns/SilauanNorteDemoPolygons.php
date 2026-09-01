<?php

namespace Database\Seeders\Concerns;

use App\Services\PolygonIntegrityService;
use RuntimeException;

/**
 * Builds four non-overlapping demo farm rectangles inside Silauan Norte.
 *
 * Anchors sit in the interior of the barangay polygon from
 * database/seeders/data/silauan-norte-boundary.json (extracted from
 * echague-barangays.geojson, pcode PH0203112057).
 */
final class SilauanNorteDemoPolygons
{
    public const BARANGAY = 'Silauan Norte (Poblacion)';

    /**
     * Interior anchors: [centerLat, centerLng, widthMeters, heightMeters].
     * Sizes land in the 0.4–0.8 ha range requested for the Spatial Inspector demo.
     *
     * @var array<int, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const ANCHORS = [
        [16.7152, 121.6700, 90.0, 75.0],  // ~0.675 ha — barangay center
        [16.7200, 121.6685, 80.0, 70.0],  // ~0.560 ha — northwest
        [16.7130, 121.6680, 85.0, 65.0],  // ~0.553 ha — southwest
        [16.7170, 121.6735, 100.0, 75.0], // ~0.750 ha — east-central
    ];

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    public static function barangayBoundary(): array
    {
        $path = database_path('seeders/data/silauan-norte-boundary.json');
        $raw = json_decode((string) file_get_contents($path), true);

        if (! is_array($raw) || empty($raw['boundary_points'])) {
            throw new RuntimeException("Missing Silauan Norte boundary at {$path}");
        }

        $points = [];
        foreach ($raw['boundary_points'] as $point) {
            if (! is_array($point) || ! isset($point['lat'], $point['lng'])) {
                continue;
            }
            $points[] = [
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
            ];
        }

        if (count($points) < 3) {
            throw new RuntimeException('Silauan Norte boundary has fewer than 3 vertices.');
        }

        return $points;
    }

    /**
     * Four mapped demo parcels: closed rectangles fully inside the barangay.
     *
     * @return array<int, array{points: array<int, array{lat: float, lng: float}>, centroid: array{lat: float, lng: float}, size_ha: float}>
     */
    public static function mappedPlots(): array
    {
        $integrity = new PolygonIntegrityService;
        $barangay = self::barangayBoundary();
        $plots = [];

        foreach (self::ANCHORS as $index => [$centerLat, $centerLng, $widthM, $heightM]) {
            $points = self::rectangle($centerLat, $centerLng, $widthM, $heightM);

            foreach ($points as $vertex) {
                if (! $integrity->pointInPolygon((float) $vertex['lat'], (float) $vertex['lng'], $barangay)) {
                    throw new RuntimeException(
                        'Demo farm '.($index + 1).' has a vertex outside Silauan Norte.'
                    );
                }
            }

            foreach ($plots as $existingIndex => $existing) {
                if ($integrity->polygonsIntersect($points, $existing['points'])) {
                    throw new RuntimeException(
                        'Demo farm '.($index + 1).' overlaps farm '.($existingIndex + 1).'.'
                    );
                }
            }

            $plots[] = [
                'points' => $points,
                'centroid' => $integrity->centroid($points),
                'size_ha' => round(self::polygonAreaHa($points), 4),
            ];
        }

        return $plots;
    }

    /**
     * Rectangle (SW → SE → NE → NW). Google Maps closes the ring when rendering.
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    private static function rectangle(float $centerLat, float $centerLng, float $widthM, float $heightM): array
    {
        $dLat = $heightM / 111_320.0;
        $dLng = $widthM / (111_320.0 * cos(deg2rad($centerLat)));

        return [
            ['lat' => $centerLat - $dLat / 2, 'lng' => $centerLng - $dLng / 2],
            ['lat' => $centerLat - $dLat / 2, 'lng' => $centerLng + $dLng / 2],
            ['lat' => $centerLat + $dLat / 2, 'lng' => $centerLng + $dLng / 2],
            ['lat' => $centerLat + $dLat / 2, 'lng' => $centerLng - $dLng / 2],
        ];
    }

    /**
     * Equirectangular shoelace area in hectares (same projection as SyncController).
     *
     * @param  array<int, array{lat: float, lng: float}>  $points
     */
    private static function polygonAreaHa(array $points): float
    {
        $count = count($points);
        if ($count < 3) {
            return 0.0;
        }

        $earthRadius = 6_371_000;
        $meanLat = array_sum(array_column($points, 'lat')) / $count;
        $cosLat0 = cos(deg2rad($meanLat));

        $xy = array_map(static function (array $p) use ($earthRadius, $cosLat0) {
            return [
                'x' => deg2rad($p['lng']) * $earthRadius * $cosLat0,
                'y' => deg2rad($p['lat']) * $earthRadius,
            ];
        }, $points);

        $area = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $area += $xy[$i]['x'] * $xy[$j]['y'] - $xy[$j]['x'] * $xy[$i]['y'];
        }

        return (abs($area) / 2) / 10_000;
    }
}
