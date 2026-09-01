<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeatherRadarTest extends TestCase
{
    use RefreshDatabase;

    public function test_radar_endpoint_returns_empty_payload_when_upstream_fails(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Http::fake([
            '*' => Http::response(null, 503),
        ]);

        $response = $this->getJson('/api/weather/radar');

        $response->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.frames', []);
    }

    public function test_radar_endpoint_returns_frames_when_upstream_succeeds(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Http::fake([
            '*/api/v1/radar/timeline*' => Http::response([
                'data' => [
                    'image_urls' => [
                        'https://cdn.panahon.gov.ph/radar/frame0.png',
                        'https://cdn.panahon.gov.ph/radar/frame1.png',
                    ],
                    'observation_dates' => [
                        '2026-09-01T12:00:00Z',
                        '2026-09-01T12:15:00Z',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/weather/radar');

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonCount(2, 'data.frames');
    }

    public function test_national_advisories_degrades_gracefully_on_upstream_failure(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $response = $this->getJson('/api/weather/national-advisories');

        $response->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.rainfall_advisories', [])
            ->assertJsonPath('data.cyclone_bulletins', []);
    }

    public function test_national_advisories_returns_payload_when_bagyo_responds(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Http::fake([
            '*/v1/rainfall/current' => Http::response([
                'data' => [
                    ['title' => 'Heavy rain over Isabela'],
                ],
            ], 200),
            '*/v1/bulletins/latest' => Http::response([
                'data' => [
                    'title' => 'Tropical Cyclone Update',
                    'cyclone_name' => 'INDAY',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/weather/national-advisories');

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonCount(1, 'data.rainfall_advisories')
            ->assertJsonCount(1, 'data.cyclone_bulletins');
    }
}
