<?php

namespace App\Support;

/**
 * Rainfall mm bands for MAO Echague Facebook forecast cards.
 */
final class RainfallBands
{
    public const NONE = 'none';

    public const LIGHT = 'light';

    public const MODERATE = 'moderate';

    public const YELLOW = 'yellow';

    public const ORANGE = 'orange';

    public const RED = 'red';

    /**
     * @return list<array{
     *   key:string,
     *   label:string,
     *   range:string,
     *   min:float,
     *   max:?float,
     *   color:string,
     *   fill:array{r:int,g:int,b:int},
     *   impacts:list<string>,
     *   highlight:bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => self::NONE,
                'label' => 'No significant rain',
                'range' => '< 5 mm',
                'min' => 0.0,
                'max' => 5.0,
                'color' => '#9CA3AF',
                'fill' => ['r' => 156, 'g' => 163, 'b' => 175],
                'impacts' => [
                    'No significant rainfall expected for farm operations.',
                ],
                'highlight' => false,
            ],
            [
                'key' => self::LIGHT,
                'label' => 'Light rain',
                'range' => '5-25 mm',
                'min' => 5.0,
                'max' => 25.0,
                'color' => '#FDE68A',
                'fill' => ['r' => 253, 'g' => 230, 'b' => 138],
                'impacts' => [
                    'Light rain; delay spraying or fertilizer where fields will stay wet.',
                ],
                'highlight' => true,
            ],
            [
                'key' => self::MODERATE,
                'label' => 'Moderate rain',
                'range' => '25-50 mm',
                'min' => 25.0,
                'max' => 50.0,
                'color' => '#FACC15',
                'fill' => ['r' => 250, 'g' => 204, 'b' => 21],
                'impacts' => [
                    'Localized ponding possible in low-lying or near-river farms.',
                ],
                'highlight' => true,
            ],
            [
                'key' => self::YELLOW,
                'label' => 'Heavy rain',
                'range' => '50-100 mm',
                'min' => 50.0,
                'max' => 100.0,
                'color' => '#EAB308',
                'fill' => ['r' => 234, 'g' => 179, 'b' => 8],
                'impacts' => [
                    'Localized flooding is possible mainly in areas that are urbanized, low-lying, or near rivers.',
                    'Landslide possible in highly susceptible areas.',
                ],
                'highlight' => true,
            ],
            [
                'key' => self::ORANGE,
                'label' => 'Intense rain',
                'range' => '100-200 mm',
                'min' => 100.0,
                'max' => 200.0,
                'color' => '#F97316',
                'fill' => ['r' => 249, 'g' => 115, 'b' => 22],
                'impacts' => [
                    'Numerous flooding events are likely, especially in areas that are urbanized, low-lying, or near rivers.',
                    'Landslide likely in moderate to highly susceptible areas.',
                ],
                'highlight' => true,
            ],
            [
                'key' => self::RED,
                'label' => 'Extreme rain',
                'range' => '200+ mm',
                'min' => 200.0,
                'max' => null,
                'color' => '#DC2626',
                'fill' => ['r' => 220, 'g' => 38, 'b' => 38],
                'impacts' => [
                    'Intense rainfall; widespread flooding risk. Secure livestock and farm inputs.',
                    'Landslide risk elevated in susceptible slopes.',
                ],
                'highlight' => true,
            ],
        ];
    }

    public static function keyForMm(?float $mm): string
    {
        if ($mm === null || $mm < 5.0) {
            return self::NONE;
        }
        if ($mm < 25.0) {
            return self::LIGHT;
        }
        if ($mm < 50.0) {
            return self::MODERATE;
        }
        if ($mm < 100.0) {
            return self::YELLOW;
        }
        if ($mm < 200.0) {
            return self::ORANGE;
        }

        return self::RED;
    }

    /**
     * @return array{key:string,label:string,range:string,min:float,max:?float,color:string,fill:array{r:int,g:int,b:int},impacts:list<string>,highlight:bool}
     */
    public static function band(string $key): array
    {
        foreach (self::definitions() as $band) {
            if ($band['key'] === $key) {
                return $band;
            }
        }

        return self::definitions()[0];
    }
}
