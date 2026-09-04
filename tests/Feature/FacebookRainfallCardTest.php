<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WeatherCache;
use App\Services\RainfallForecastComposer;
use App\Support\RainfallBands;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FacebookRainfallCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.facebook.page_id' => null,
            'services.facebook.page_access_token' => null,
            'services.facebook.graph_version' => 'v21.0',
            'services.facebook.graph_base_url' => 'https://graph.facebook.com',
        ]);
    }

    public function test_composer_maps_mm_to_bands_and_caption(): void
    {
        $today = Carbon::now('Asia/Manila')->toDateString();

        WeatherCache::create([
            'barangay_name' => 'Salvacion',
            'forecast_date' => $today,
            'precipitation_sum' => 12.5,
            'precipitation_probability' => 60,
        ]);
        WeatherCache::create([
            'barangay_name' => 'Soyung (Poblacion)',
            'forecast_date' => $today,
            'precipitation_sum' => 75.0,
            'precipitation_probability' => 90,
        ]);
        WeatherCache::create([
            'barangay_name' => 'Dicaraoyan',
            'forecast_date' => $today,
            'precipitation_sum' => 1.0,
            'precipitation_probability' => 10,
        ]);

        $payload = app(RainfallForecastComposer::class)->compose('today');

        $this->assertTrue($payload['has_data']);
        $this->assertSame($today, $payload['forecast_date']);

        $byName = collect($payload['barangays'])->keyBy('name');
        $this->assertSame(RainfallBands::LIGHT, $byName['Salvacion']['band']);
        $this->assertSame(RainfallBands::YELLOW, $byName['Soyung (Poblacion)']['band']);
        $this->assertSame(RainfallBands::NONE, $byName['Dicaraoyan']['band']);

        $legendKeys = collect($payload['legend'])->pluck('key')->all();
        $this->assertContains(RainfallBands::LIGHT, $legendKeys);
        $this->assertContains(RainfallBands::YELLOW, $legendKeys);
        $this->assertNotContains(RainfallBands::NONE, $legendKeys);
        $this->assertStringContainsString('MAO Echague', $payload['caption']);
        $this->assertStringContainsString('Open-Meteo', $payload['caption']);
    }

    public function test_png_endpoint_returns_image_for_admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $today = Carbon::now('Asia/Manila')->toDateString();

        WeatherCache::create([
            'barangay_name' => 'Salvacion',
            'forecast_date' => $today,
            'precipitation_sum' => 40.0,
        ]);

        $response = $this->get('/api/weather/facebook-card.png?window=today');

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
    }

    public function test_technician_cannot_access_facebook_card(): void
    {
        Sanctum::actingAs(User::factory()->technician()->create());

        $this->getJson('/api/weather/facebook-card?window=today')->assertForbidden();
        $this->get('/api/weather/facebook-card.png?window=today')->assertForbidden();
        $this->postJson('/api/weather/facebook-card/post', ['window' => 'today'])->assertForbidden();
    }

    public function test_post_returns_422_when_facebook_not_configured(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $today = Carbon::now('Asia/Manila')->toDateString();

        WeatherCache::create([
            'barangay_name' => 'Salvacion',
            'forecast_date' => $today,
            'precipitation_sum' => 55.0,
        ]);

        $this->postJson('/api/weather/facebook-card/post', [
            'window' => 'today',
            'caption' => 'Test caption',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_post_success_with_graph_fake_and_duplicate_warning(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        config([
            'services.facebook.page_id' => '123456789',
            'services.facebook.page_access_token' => 'page-token',
        ]);

        $today = Carbon::now('Asia/Manila')->toDateString();
        WeatherCache::create([
            'barangay_name' => 'Salvacion',
            'forecast_date' => $today,
            'precipitation_sum' => 60.0,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'photo-1',
                'post_id' => '123456789_999',
            ], 200),
        ]);

        $first = $this->postJson('/api/weather/facebook-card/post', [
            'window' => 'today',
            'caption' => 'MAO Echague rainfall forecast',
        ]);

        $first->assertOk()
            ->assertJsonPath('data.post.facebook_post_id', '123456789_999');

        $dup = $this->postJson('/api/weather/facebook-card/post', [
            'window' => 'today',
            'caption' => 'MAO Echague rainfall forecast',
        ]);
        $dup->assertStatus(422);

        $repost = $this->postJson('/api/weather/facebook-card/post', [
            'window' => 'today',
            'caption' => 'Repost caption',
            'force' => true,
        ]);
        $repost->assertOk()
            ->assertJsonPath('data.warning', 'Reposted the same forecast window.');

        $history = $this->getJson('/api/weather/facebook-posts');
        $history->assertOk();
        $this->assertGreaterThanOrEqual(2, count($history->json('data')));
    }

    public function test_super_admin_can_read_facebook_status(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        config([
            'services.facebook.page_id' => 'page-1',
            'services.facebook.page_access_token' => 'token-1',
        ]);

        $this->getJson('/api/system/facebook-status')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.page_id_set', true)
            ->assertJsonPath('data.token_set', true);
    }

    public function test_admin_cannot_read_facebook_status(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/system/facebook-status')->assertForbidden();
    }
}
