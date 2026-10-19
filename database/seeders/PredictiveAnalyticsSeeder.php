<?php

namespace Database\Seeders;

use App\Models\DamageAssessment;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\HarvestLog;
use App\Models\PestOutbreak;
use App\Models\PlantingLog;
use App\Models\User;
use App\Models\WeatherCache;
use Carbon\Carbon;
use Database\Seeders\Concerns\EchagueBarangays;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

/**
 * Demo harvest, planting, calamity, and weather rows for Echague so the
 * dashboard yield forecast and risk charts have municipal figures.
 * Safe to run again: records are keyed by stable ids.
 */
class PredictiveAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        $technician = User::query()->where('email', 'john@mao.com')->first()
            ?? User::query()->where('role', 'admin')->first();

        if (! $technician) {
            $this->call(UserSeeder::class);
            $technician = User::query()->where('email', 'john@mao.com')->first();
        }

        $today = Carbon::today();
        $wetYears = [$today->year - 2, $today->year - 1];

        foreach (EchagueBarangays::ALL as $index => $barangay) {
            $farmer = $this->farmer($index, $barangay);
            $plot = $this->plot($farmer, $barangay, $index);

            $this->seedCrop($farmer, $barangay, 'Rice', 'NSIC Rc 222', $index, $wetYears, 8 + ($index % 10), 4200);
            if ($index % 3 === 0) {
                $this->seedCrop($farmer, $barangay, 'Corn', 'Dekalb 6919', $index + 100, $wetYears, 4 + ($index % 6), 4800);
            }

            $this->seedRisk($technician, $farmer, $plot, $barangay, $index, $today);
        }

        $this->command?->info('Echague predictive analytics demo data is ready for '.count(EchagueBarangays::ALL).' barangays.');
    }

    private function farmer(int $index, string $barangay): Farmer
    {
        $rsbsa = sprintf('RSBSA-ECH-DEMO-%03d', $index + 1);
        $existing = Farmer::query()->where('rsbsa_no', $rsbsa)->first();
        if ($existing) {
            return $existing;
        }

        return Farmer::factory()->inBarangay($barangay)->create([
            'rsbsa_no' => $rsbsa,
            'surname' => 'Farmer',
            'first_name' => 'Echague '.($index + 1),
            'permanent_city' => 'Echague',
            'permanent_province' => 'Isabela',
            'permanent_region' => 'Region II',
            'farm_brgy' => $barangay,
            'farm_city' => 'Echague',
        ]);
    }

    private function plot(Farmer $farmer, string $barangay, int $index): FarmPlot
    {
        return FarmPlot::query()->updateOrCreate(
            [
                'farmer_id' => $farmer->id,
                'parcel_name' => 'Echague forecast demo',
            ],
            [
                'location_brgy' => $barangay,
                'location_city' => 'Echague',
                'location_province' => 'Isabela',
                'total_parcel_area_ha' => 3 + ($index % 5),
                'ownership_type' => 'Owner',
                'proof_of_ownership_document' => 'Tax Declaration',
                'commodity' => 'Rice',
                'size_ha' => 2 + ($index % 4),
                'farm_type' => 'Irrigated',
            ]
        );
    }

    /**
     * @param  array<int, int>  $wetYears
     */
    private function seedCrop(
        Farmer $farmer,
        string $barangay,
        string $crop,
        string $variety,
        int $salt,
        array $wetYears,
        float $plantedHa,
        float $baseKgPerHa,
    ): void {
        foreach ($wetYears as $offset => $year) {
            $kgPerHa = $baseKgPerHa + ($salt % 9) * 70 + ($offset * 180);
            $area = round(max(1, $plantedHa - 2), 2);
            HarvestLog::query()->updateOrCreate(
                ['client_id' => $this->demoId("harvest:{$barangay}:{$crop}:{$year}")],
                [
                    'farmer_id' => $farmer->id,
                    'crop_type' => $crop,
                    'variety' => $variety,
                    'area_harvested' => $area,
                    'total_yield' => round($area * $kgPerHa, 2),
                    'yield_unit' => 'Kilograms',
                    'date_harvested' => sprintf('%d-08-15', $year),
                    'farm_location' => $barangay,
                ]
            );
        }

        PlantingLog::query()->updateOrCreate(
            ['client_id' => $this->demoId("planting:{$barangay}:{$crop}")],
            [
                'farmer_id' => $farmer->id,
                'crop_type' => $crop,
                'variety' => $variety,
                'area_planted' => round($plantedHa, 2),
                'date_planted' => Carbon::today()->subDays(40)->toDateString(),
                'status' => 'Active',
                'farm_location' => $barangay,
            ]
        );
    }

    private function seedRisk(User $technician, Farmer $farmer, FarmPlot $plot, string $barangay, int $index, Carbon $today): void
    {
        $band = $index % 4;
        $calamity = match ($band) {
            0 => ['Typhoon', 'Typhoon demo', 78],
            1 => ['Drought', 'Drought demo', 62],
            2 => ['Flood', 'Flood demo', 55],
            default => null,
        };

        if ($calamity) {
            [$type, $name, $percent] = $calamity;
            DamageAssessment::query()->updateOrCreate(
                ['device_id' => 'echague-forecast-'.$this->demoId("damage:{$barangay}")],
                [
                    'farm_plot_id' => $plot->id,
                    'farmer_id' => $farmer->id,
                    'technician_id' => $technician->id,
                    'calamity_name' => $name,
                    'calamity_type' => $type,
                    'date_of_calamity' => $today->copy()->subMonths(4)->toDateString(),
                    'damage_percentage' => $percent,
                    'area_planted_ha' => $plot->size_ha,
                    'status' => 'Verified',
                    'remarks' => 'Echague forecast demo',
                ]
            );
        }

        $precip = match ($band) {
            2 => 90,
            0 => 70,
            default => 25,
        };
        $et0 = $band === 1 ? 6.4 : 2.8;

        foreach ([0, 1, 2] as $day) {
            WeatherCache::query()->updateOrCreate(
                [
                    'barangay_name' => $barangay,
                    'forecast_date' => $today->copy()->addDays($day)->toDateString(),
                ],
                [
                    'temperature_min' => 24,
                    'temperature_max' => 33,
                    'precipitation_probability' => $precip,
                    'precipitation_sum' => $precip >= 80 ? 40 : 4,
                    'evapotranspiration' => $et0,
                    'wind_speed_10m' => 8,
                    'weather_code' => $precip >= 80 ? 65 : 1,
                ]
            );
        }

        if ($band === 0) {
            PestOutbreak::query()->updateOrCreate(
                [
                    'farm_plot_id' => $plot->id,
                    'pest_name' => 'Brown Planthopper',
                    'date_spotted' => $today->copy()->subDays(12)->toDateString(),
                ],
                [
                    'technician_id' => $technician->id,
                    'severity' => 'High',
                    'status' => 'Active',
                    'notes' => 'Echague forecast demo',
                ]
            );
        }
    }

    private function demoId(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'agri-akap.echague.forecast.'.$key)->toString();
    }
}
