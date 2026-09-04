<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\WeatherCache;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherPrecipitationSumFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_fetch_upserts_precipitation_sum(): void
    {
        Barangay::create([
            'name' => 'Salvacion',
            'latitude' => 16.71,
            'longitude' => 121.66,
            'is_active' => true,
        ]);

        $today = Carbon::now('Asia/Manila')->toDateString();

        Http::fake([
            'api.open-meteo.com/*' => Http::response([
                'daily' => [
                    'time' => [$today],
                    'temperature_2m_min' => [24.1],
                    'temperature_2m_max' => [32.2],
                    'precipitation_probability_max' => [80],
                    'precipitation_sum' => [42.5],
                    'et0_fao_evapotranspiration' => [4.2],
                    'windspeed_10m_max' => [12.0],
                    'weathercode' => [61],
                ],
                'hourly' => [
                    'time' => [$today.'T00:00', $today.'T12:00'],
                    'soil_moisture_7_to_28cm' => [0.31, 0.33],
                ],
            ], 200),
        ]);

        $result = app(WeatherService::class)->fetchAndCache();

        $this->assertSame(1, $result['synced']);

        $row = WeatherCache::query()->where('barangay_name', 'Salvacion')->first();
        $this->assertNotNull($row);
        $this->assertSame(80, $row->precipitation_probability);
        $this->assertEquals(42.5, (float) $row->precipitation_sum);
    }
}
