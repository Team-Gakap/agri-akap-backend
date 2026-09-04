<?php

namespace App\Services;

use App\Support\EchagueGeoName;
use App\Support\RainfallBands;
use RuntimeException;

/**
 * Composites a PAGASA-style landscape rainfall forecast card (MAO branding).
 */
class RainfallForecastCardRenderer
{
    public const WIDTH = 1200;

    public const HEIGHT = 675;

    /**
     * @param  array{
     *   window:string,
     *   forecast_date:string,
     *   validity_label:string,
     *   issued_label:string,
     *   title:string,
     *   subtitle:string,
     *   attribution:string,
     *   barangays:list<array{name:string,short_name:string,precipitation_sum:?float,band:string,color:string}>,
     *   legend:list<array{key:string,label:string,range:string,color:string,impacts:list<string>,barangays:list<string>}>
     * }  $payload
     */
    public function render(array $payload): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required to render rainfall cards.');
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            throw new RuntimeException('Unable to allocate image canvas.');
        }

        imagesavealpha($img, true);
        imagealphablending($img, true);

        $navy = imagecolorallocate($img, 15, 39, 68);
        $panel = imagecolorallocate($img, 23, 55, 94);
        $white = imagecolorallocate($img, 255, 255, 255);
        $chip = imagecolorallocate($img, 125, 178, 214);
        $footerBg = imagecolorallocate($img, 245, 247, 250);
        $footerText = imagecolorallocate($img, 55, 65, 81);
        $muted = imagecolorallocate($img, 203, 213, 225);
        $mapBg = imagecolorallocate($img, 30, 58, 95);

        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $navy);
        imagefilledrectangle($img, 0, 0, 700, 600, $mapBg);
        imagefilledrectangle($img, 700, 0, self::WIDTH, 600, $panel);
        imagefilledrectangle($img, 0, 600, self::WIDTH, self::HEIGHT, $footerBg);

        $font = $this->fontPath(false);
        $fontBold = $this->fontPath(true);

        // Title + validity on right panel
        imagettftext($img, 22, 0, 730, 48, $white, $fontBold, $payload['title']);
        $this->drawRoundedRect($img, 730, 62, 1160, 100, 8, $chip);
        imagettftext($img, 11, 0, 744, 86, $navy, $fontBold, $this->ellipsize($payload['validity_label'], 52));

        imagettftext($img, 12, 0, 730, 130, $muted, $font, 'Potential Impacts');

        $y = 150;
        foreach ($payload['legend'] as $block) {
            $band = RainfallBands::band($block['key']);
            $fill = imagecolorallocate($img, $band['fill']['r'], $band['fill']['g'], $band['fill']['b']);
            imagefilledrectangle($img, 730, $y, 1160, $y + 28, $fill);
            $labelColor = in_array($block['key'], [RainfallBands::LIGHT, RainfallBands::MODERATE, RainfallBands::YELLOW], true)
                ? $navy
                : $white;
            imagettftext($img, 12, 0, 744, $y + 20, $labelColor, $fontBold, "({$block['range']})");
            $y += 38;

            foreach ($block['impacts'] as $impact) {
                $wrapped = $this->wrapText($impact, 42);
                foreach ($wrapped as $line) {
                    imagettftext($img, 10, 0, 744, $y, $white, $font, '• '.$line);
                    $y += 16;
                }
                $y += 4;
            }
            $y += 10;
            if ($y > 560) {
                break;
            }
        }

        if ($payload['legend'] === []) {
            imagettftext($img, 11, 0, 730, 180, $muted, $font, 'No elevated rainfall bands for this window.');
            imagettftext($img, 11, 0, 730, 202, $muted, $font, 'All barangays are below 5 mm.');
        }

        $this->drawMap($img, $payload['barangays'], $font, $fontBold);

        // Footer
        imagettftext($img, 12, 0, 90, 635, $footerText, $fontBold, 'Weather Advisory — MAO Echague');
        imagettftext($img, 10, 0, 90, 656, $footerText, $font, $payload['issued_label']);

        $attr = $this->ellipsize($payload['attribution'], 70);
        imagettftext($img, 9, 0, 520, 656, $footerText, $font, $attr);

        $this->drawSeal($img, 18, 610, 56);

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);

        if ($png === false || $png === '') {
            throw new RuntimeException('Failed to encode rainfall card PNG.');
        }

        return $png;
    }

    /**
     * @param  list<array{name:string,short_name:string,precipitation_sum:?float,band:string,color:string}>  $barangays
     */
    protected function drawMap($img, array $barangays, string $font, string $fontBold): void
    {
        $geoPath = resource_path('geo/echague-barangays.geojson');
        if (! is_file($geoPath)) {
            throw new RuntimeException('Echague GeoJSON missing at resources/geo/echague-barangays.geojson');
        }

        $raw = file_get_contents($geoPath);
        $geo = json_decode($raw ?: '', true);
        if (! is_array($geo) || ! isset($geo['features']) || ! is_array($geo['features'])) {
            throw new RuntimeException('Invalid Echague GeoJSON.');
        }

        $byOfficial = [];
        foreach ($barangays as $row) {
            $byOfficial[EchagueGeoName::normalize($row['name'])] = $row;
        }

        $bounds = $this->computeBounds($geo['features']);
        $mapBox = ['x' => 24, 'y' => 24, 'w' => 650, 'h' => 552];

        $labels = [];

        foreach ($geo['features'] as $feature) {
            if (! is_array($feature)) {
                continue;
            }
            $props = $feature['properties'] ?? [];
            $geoName = is_array($props) ? (string) ($props['adm4_name'] ?? '') : '';
            $official = EchagueGeoName::toOfficial($geoName);
            $row = $byOfficial[EchagueGeoName::normalize($official)]
                ?? $byOfficial[EchagueGeoName::normalize($geoName)]
                ?? null;

            $bandKey = $row['band'] ?? RainfallBands::NONE;
            $band = RainfallBands::band($bandKey);
            $fill = imagecolorallocatealpha(
                $img,
                $band['fill']['r'],
                $band['fill']['g'],
                $band['fill']['b'],
                $bandKey === RainfallBands::NONE ? 40 : 0
            );
            $stroke = imagecolorallocate($img, 30, 41, 59);

            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry)) {
                continue;
            }

            $centroid = $this->paintGeometry($img, $geometry, $bounds, $mapBox, $fill, $stroke);
            if ($centroid && $row && ($row['band'] ?? RainfallBands::NONE) !== RainfallBands::NONE) {
                $labels[] = [
                    'x' => $centroid[0],
                    'y' => $centroid[1],
                    'text' => $row['short_name'],
                ];
            }
        }

        foreach ($labels as $label) {
            $box = imagettfbbox(8, 0, $fontBold, $label['text']);
            if ($box === false) {
                continue;
            }
            $tw = abs($box[2] - $box[0]);
            $th = abs($box[7] - $box[1]);
            $lx = (int) round($label['x'] - $tw / 2);
            $ly = (int) round($label['y'] + $th / 2);
            $pad = 3;
            $bg = imagecolorallocatealpha($img, 255, 255, 255, 40);
            imagefilledrectangle(
                $img,
                $lx - $pad,
                $ly - $th - $pad,
                $lx + $tw + $pad,
                $ly + $pad,
                $bg
            );
            imagettftext($img, 8, 0, $lx, $ly, imagecolorallocate($img, 17, 24, 39), $fontBold, $label['text']);
        }
    }

    /**
     * @param  list<mixed>  $features
     * @return array{minLng:float,maxLng:float,minLat:float,maxLat:float}
     */
    protected function computeBounds(array $features): array
    {
        $minLng = 180.0;
        $maxLng = -180.0;
        $minLat = 90.0;
        $maxLat = -90.0;

        foreach ($features as $feature) {
            if (! is_array($feature) || ! isset($feature['geometry']['coordinates'])) {
                continue;
            }
            $this->walkCoords($feature['geometry']['coordinates'], function (float $lng, float $lat) use (&$minLng, &$maxLng, &$minLat, &$maxLat) {
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
            });
        }

        $padLng = ($maxLng - $minLng) * 0.04;
        $padLat = ($maxLat - $minLat) * 0.04;

        return [
            'minLng' => $minLng - $padLng,
            'maxLng' => $maxLng + $padLng,
            'minLat' => $minLat - $padLat,
            'maxLat' => $maxLat + $padLat,
        ];
    }

    /**
     * @param  array{minLng:float,maxLng:float,minLat:float,maxLat:float}  $bounds
     * @param  array{x:int,y:int,w:int,h:int}  $mapBox
     * @return array{0:float,1:float}|null
     */
    protected function paintGeometry($img, array $geometry, array $bounds, array $mapBox, int $fill, int $stroke): ?array
    {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        if (! is_array($coords)) {
            return null;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $n = 0;

        $rings = [];
        if ($type === 'Polygon') {
            $rings = [$coords];
        } elseif ($type === 'MultiPolygon') {
            $rings = $coords;
        }

        foreach ($rings as $polygon) {
            if (! is_array($polygon) || $polygon === []) {
                continue;
            }
            $outer = $polygon[0] ?? null;
            if (! is_array($outer) || count($outer) < 3) {
                continue;
            }
            $points = [];
            foreach ($outer as $pt) {
                if (! is_array($pt) || count($pt) < 2) {
                    continue;
                }
                [$x, $y] = $this->project((float) $pt[0], (float) $pt[1], $bounds, $mapBox);
                $points[] = (int) round($x);
                $points[] = (int) round($y);
                $sumX += $x;
                $sumY += $y;
                $n++;
            }
            $count = intdiv(count($points), 2);
            if ($count >= 3) {
                imagefilledpolygon($img, $points, $fill);
                imagepolygon($img, $points, $stroke);
            }
        }

        if ($n === 0) {
            return null;
        }

        return [$sumX / $n, $sumY / $n];
    }

    /**
     * @param  array{minLng:float,maxLng:float,minLat:float,maxLat:float}  $bounds
     * @param  array{x:int,y:int,w:int,h:int}  $mapBox
     * @return array{0:float,1:float}
     */
    protected function project(float $lng, float $lat, array $bounds, array $mapBox): array
    {
        $xRatio = ($lng - $bounds['minLng']) / max($bounds['maxLng'] - $bounds['minLng'], 0.00001);
        $yRatio = ($bounds['maxLat'] - $lat) / max($bounds['maxLat'] - $bounds['minLat'], 0.00001);

        return [
            $mapBox['x'] + $xRatio * $mapBox['w'],
            $mapBox['y'] + $yRatio * $mapBox['h'],
        ];
    }

    protected function walkCoords(mixed $node, callable $visitor): void
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node[0], $node[1]) && is_numeric($node[0]) && is_numeric($node[1]) && ! is_array($node[0])) {
            $visitor((float) $node[0], (float) $node[1]);

            return;
        }
        foreach ($node as $child) {
            $this->walkCoords($child, $visitor);
        }
    }

    protected function drawSeal($img, int $x, int $y, int $size): void
    {
        $path = resource_path('images/echague-logo.png');
        if (! is_file($path)) {
            return;
        }
        $seal = @imagecreatefrompng($path);
        if ($seal === false) {
            return;
        }
        $sw = imagesx($seal);
        $sh = imagesy($seal);
        imagecopyresampled($img, $seal, $x, $y, 0, 0, $size, $size, $sw, $sh);
        imagedestroy($seal);
    }

    protected function drawRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    /** @return list<string> */
    protected function wrapText(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $next = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($next) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $next;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    protected function ellipsize(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, max($max - 1, 1)).'…';
    }

    protected function fontPath(bool $bold): string
    {
        $path = $bold
            ? resource_path('fonts/DejaVuSans-Bold.ttf')
            : resource_path('fonts/DejaVuSans.ttf');

        if (! is_file($path)) {
            throw new RuntimeException('Missing TTF font at '.$path);
        }

        return $path;
    }
}
