<?php

namespace Tests\Feature;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\HarvestLog;
use App\Models\PlantingLog;
use App\Models\User;
use App\Models\WeatherCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PredictiveAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_forecast_uses_harvest_history_and_flags_typhoon_and_drought(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00'));

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $farmer = Farmer::factory()->inBarangay('Salvacion')->create();
        $plot = FarmPlot::create([
            'farmer_id' => $farmer->id,
            'location_brgy' => 'Salvacion',
            'location_city' => 'Echague',
            'location_province' => 'Isabela',
            'total_parcel_area_ha' => 2,
            'ownership_type' => 'Owner',
            'proof_of_ownership_document' => 'Tax Declaration',
            'commodity' => 'Rice',
            'size_ha' => 2,
            'farm_type' => 'Irrigated',
        ]);

        HarvestLog::create([
            'farmer_id' => $farmer->id,
            'crop_type' => 'Rice',
            'variety' => 'NSIC Rc 222',
            'area_harvested' => 2,
            'total_yield' => 8000,
            'yield_unit' => 'Kilograms',
            'date_harvested' => '2024-08-15',
            'farm_location' => 'Salvacion',
        ]);
        HarvestLog::create([
            'farmer_id' => $farmer->id,
            'crop_type' => 'Rice',
            'variety' => 'NSIC Rc 222',
            'area_harvested' => 2,
            'total_yield' => 10000,
            'yield_unit' => 'Kilograms',
            'date_harvested' => '2025-08-15',
            'farm_location' => 'Salvacion',
        ]);
        PlantingLog::create([
            'farmer_id' => $farmer->id,
            'crop_type' => 'Rice',
            'variety' => 'NSIC Rc 222',
            'area_planted' => 10,
            'date_planted' => '2026-06-01',
            'status' => 'Active',
            'farm_location' => 'Salvacion',
        ]);

        DamageAssessment::create([
            'farm_plot_id' => $plot->id,
            'farmer_id' => $farmer->id,
            'technician_id' => $admin->id,
            'calamity_name' => 'Typhoon Ompong',
            'calamity_type' => 'Typhoon',
            'date_of_calamity' => '2025-09-20',
            'damage_percentage' => 80,
            'status' => 'Pending',
        ]);

        WeatherCache::create([
            'barangay_name' => 'Salvacion',
            'forecast_date' => '2026-10-19',
            'precipitation_probability' => 20,
            'evapotranspiration' => 6.2,
            'weather_code' => 1,
        ]);

        $response = $this->getJson('/api/dashboard/predictive-analytics');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.summary.season', 'Wet')
            ->assertJsonPath('data.summary.direction', 'surplus');

        $row = collect($response->json('data.barangays'))->firstWhere('barangay', 'Salvacion');
        $this->assertNotNull($row);
        $this->assertEquals('Rice', $row['commodity']);
        $this->assertEqualsWithDelta(49.5, $row['projected_mt'], 0.05);
        $this->assertGreaterThan(0, $row['variance_mt']);
        $this->assertTrue($row['typhoon_flag']);
        $this->assertTrue($row['drought_flag']);
        $this->assertGreaterThan(0, $row['buffer_bags']);
        $this->assertSame('High', $row['confidence']);

        $filtered = $this->getJson('/api/dashboard/predictive-analytics?commodity=Corn');
        $filtered->assertOk();
        $this->assertSame([], $filtered->json('data.barangays'));
        $this->assertEquals(0, $filtered->json('data.summary.projected_mt'));
    }
}
