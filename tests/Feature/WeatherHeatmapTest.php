<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WeatherCache;
use App\Models\WeatherCurrent;
use App\Models\WeatherHourly;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeatherHeatmapTest extends TestCase
{
    use RefreshDatabase;

    public function test_heatmap_includes_next_6h_precip_meta_and_current_conditions(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $today = Carbon::now('Asia/Manila')->toDateString();
        $now = Carbon::now('Asia/Manila');

        WeatherCache::create([
            'barangay_name' => 'Salvacion',
            'forecast_date' => $today,
            'precipitation_probability' => 0,
            'soil_moisture_28cm' => 0.32,
            'wind_speed_10m' => 25.0,
            'evapotranspiration' => 5.5,
            'weather_code' => 3,
        ]);

        WeatherHourly::create([
            'barangay_name' => 'Salvacion',
            'forecast_datetime' => $now->copy()->addHour(),
            'precipitation_probability' => 45,
            'temperature' => 29.0,
            'wind_speed' => 12.0,
            'weather_code' => 3,
        ]);

        WeatherHourly::create([
            'barangay_name' => 'Salvacion',
            'forecast_datetime' => $now->copy()->addHours(3),
            'precipitation_probability' => 72,
            'temperature' => 28.0,
            'wind_speed' => 14.0,
            'weather_code' => 61,
        ]);

        WeatherCurrent::create([
            'barangay_name' => 'Salvacion',
            'observed_at' => $now,
            'temperature' => 30.5,
            'precipitation' => 0.2,
            'rain' => 0.1,
            'precipitation_probability' => 15,
            'wind_speed' => 11.0,
            'weather_code' => 3,
        ]);

        $response = $this->getJson('/api/weather/heatmap');

        $response->assertOk()
            ->assertJsonPath('data.barangays.0.barangay_name', 'Salvacion')
            ->assertJsonPath('data.barangays.0.precipitation_probability_daily', 0)
            ->assertJsonPath('data.barangays.0.precipitation_probability_next_6h', 72)
            ->assertJsonPath('data.barangays.0.current_conditions.temperature', 30.5)
            ->assertJsonPath('data.barangays.0.current_conditions.source', 'Open-Meteo model now-cast')
            ->assertJsonStructure([
                'data' => [
                    'meta' => [
                        'timezone',
                        'daily_synced_at',
                        'hourly_synced_at',
                        'current_synced_at',
                        'generated_at',
                    ],
                ],
            ]);
    }
}
