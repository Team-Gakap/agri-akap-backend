<?php

namespace App\Services;

use App\Models\WeatherCache;
use App\Support\EchagueGeoName;
use App\Support\RainfallBands;
use Carbon\Carbon;
use InvalidArgumentException;

class RainfallForecastComposer
{
    public const WINDOW_TODAY = 'today';

    public const WINDOW_TOMORROW = 'tomorrow';

    /**
     * @return array{
     *   window:string,
     *   forecast_date:string,
     *   validity_label:string,
     *   issued_at:string,
     *   issued_label:string,
     *   title:string,
     *   subtitle:string,
     *   attribution:string,
     *   barangays:list<array{name:string,short_name:string,precipitation_sum:?float,band:string,color:string}>,
     *   legend:list<array{key:string,label:string,range:string,color:string,impacts:list<string>,barangays:list<string>}>,
     *   caption:string,
     *   has_data:bool
     * }
     */
    public function compose(string $window): array
    {
        $window = $this->normalizeWindow($window);
        $tz = WeatherService::TIMEZONE;
        $now = Carbon::now($tz);
        $forecastDate = $window === self::WINDOW_TOMORROW
            ? $now->copy()->addDay()->toDateString()
            : $now->toDateString();

        $rows = WeatherCache::query()
            ->whereDate('forecast_date', $forecastDate)
            ->orderBy('barangay_name')
            ->get();

        $barangays = [];
        $byBand = [];

        foreach ($rows as $row) {
            $mm = $row->precipitation_sum !== null ? (float) $row->precipitation_sum : null;
            $bandKey = RainfallBands::keyForMm($mm);
            $band = RainfallBands::band($bandKey);
            $name = (string) $row->barangay_name;
            $short = EchagueGeoName::shortLabel($name);

            $barangays[] = [
                'name' => $name,
                'short_name' => $short,
                'precipitation_sum' => $mm,
                'band' => $bandKey,
                'color' => $band['color'],
            ];

            $byBand[$bandKey][] = $short;
        }

        $legend = [];
        foreach (RainfallBands::definitions() as $def) {
            if (! $def['highlight']) {
                continue;
            }
            $names = $byBand[$def['key']] ?? [];
            if ($names === []) {
                continue;
            }
            $legend[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'range' => $def['range'],
                'color' => $def['color'],
                'impacts' => $def['impacts'],
                'barangays' => $names,
            ];
        }

        $validityLabel = $this->validityLabel($window, $forecastDate, $now);
        $issuedLabel = $now->format('g:i A').' today, '.$now->format('j F Y');

        return [
            'window' => $window,
            'forecast_date' => $forecastDate,
            'validity_label' => $validityLabel,
            'issued_at' => $now->toIso8601String(),
            'issued_label' => $issuedLabel,
            'title' => '24 - HR RAINFALL FORECAST',
            'subtitle' => 'Municipal Agriculture Office — Echague, Isabela',
            'attribution' => 'MAO farm advisory from Open-Meteo model data — not an official PAGASA bulletin.',
            'barangays' => $barangays,
            'legend' => $legend,
            'caption' => $this->buildCaption($window, $forecastDate, $validityLabel, $legend, $barangays),
            'has_data' => $barangays !== [],
        ];
    }

    public function normalizeWindow(string $window): string
    {
        $window = strtolower(trim($window));
        if (! in_array($window, [self::WINDOW_TODAY, self::WINDOW_TOMORROW], true)) {
            throw new InvalidArgumentException('Window must be today or tomorrow.');
        }

        return $window;
    }

    /**
     * @param  list<array{key:string,label:string,range:string,color:string,impacts:list<string>,barangays:list<string>}>  $legend
     * @param  list<array{name:string,short_name:string,precipitation_sum:?float,band:string,color:string}>  $barangays
     */
    protected function buildCaption(
        string $window,
        string $forecastDate,
        string $validityLabel,
        array $legend,
        array $barangays,
    ): string {
        $lines = [
            'MAO Echague 24-Hour Rainfall Forecast',
            $validityLabel,
            '',
        ];

        if ($legend === []) {
            $lines[] = 'No barangay is forecast above light rainfall thresholds for this window.';
        } else {
            foreach ($legend as $block) {
                $list = implode(', ', $block['barangays']);
                $lines[] = "{$block['label']} ({$block['range']}): {$list}";
            }
        }

        $lines[] = '';
        $lines[] = 'Source: Open-Meteo (calendar-day total). Not an official PAGASA bulletin.';
        $lines[] = 'Municipal Agriculture Office — Echague, Isabela';

        return implode("\n", $lines);
    }

    protected function validityLabel(string $window, string $forecastDate, Carbon $now): string
    {
        $date = Carbon::parse($forecastDate, WeatherService::TIMEZONE);
        $dayLabel = $date->format('F j');

        if ($window === self::WINDOW_TOMORROW) {
            return "Tomorrow ({$dayLabel}) — calendar-day rainfall total";
        }

        return "Today ({$dayLabel}) — calendar-day rainfall total";
    }
}
