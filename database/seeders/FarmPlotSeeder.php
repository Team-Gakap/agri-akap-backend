<?php

namespace Database\Seeders;

use App\Models\Farmer;
use App\Models\FarmPlot;
use Database\Seeders\Concerns\SilauanNorteDemoPolygons;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo farm plots for Silauan Norte: four geotagged polygons plus one pending walk.
 */
class FarmPlotSeeder extends Seeder
{
    public function run(): void
    {
        $brgy = SilauanNorteDemoPolygons::BARANGAY;
        $mapped = SilauanNorteDemoPolygons::mappedPlots();

        $plots = [
            [
                'id' => 'b1000001-0000-4000-8000-000000000001',
                'farmer_id' => 'a1000001-0000-4000-8000-000000000001',
                'parcel_name' => 'Centro Paddy',
                'commodity' => 'Rice',
                'planting_start_month' => 'June',
                'planting_end_month' => 'October',
                'mapped' => $mapped[0],
                'georef_id' => 'DEMO-SN-001',
            ],
            [
                'id' => 'b1000002-0000-4000-8000-000000000002',
                'farmer_id' => 'a1000002-0000-4000-8000-000000000002',
                'parcel_name' => 'Northwest Corn Lot',
                'commodity' => 'Corn',
                'planting_start_month' => 'May',
                'planting_end_month' => 'September',
                'mapped' => $mapped[1],
                'georef_id' => 'DEMO-SN-002',
            ],
            [
                'id' => 'b1000003-0000-4000-8000-000000000003',
                'farmer_id' => 'a1000003-0000-4000-8000-000000000003',
                'parcel_name' => 'Southwest Irrigated Field',
                'commodity' => 'Rice',
                'planting_start_month' => 'July',
                'planting_end_month' => 'November',
                'mapped' => $mapped[2],
                'georef_id' => 'DEMO-SN-003',
            ],
            [
                'id' => 'b1000004-0000-4000-8000-000000000004',
                'farmer_id' => 'a1000004-0000-4000-8000-000000000004',
                'parcel_name' => 'East Garden Plots',
                'commodity' => 'Vegetables',
                'planting_start_month' => 'January',
                'planting_end_month' => 'April',
                'mapped' => $mapped[3],
                'georef_id' => 'DEMO-SN-004',
            ],
        ];

        foreach ($plots as $plot) {
            $mappedPlot = $plot['mapped'];
            $sizeHa = $mappedPlot['size_ha'];

            FarmPlot::updateOrCreate(
                ['id' => $plot['id']],
                [
                    'farmer_id' => $plot['farmer_id'],
                    'parcel_name' => $plot['parcel_name'],
                    'location_brgy' => $brgy,
                    'location_city' => 'Echague',
                    'location_province' => 'Isabela',
                    'latitude' => $mappedPlot['centroid']['lat'],
                    'longitude' => $mappedPlot['centroid']['lng'],
                    'georef_id' => $plot['georef_id'],
                    'total_parcel_area_ha' => $sizeHa,
                    'ownership_type' => 'Registered Owner',
                    'proof_of_ownership_document' => 'Demo Seeder',
                    'commodity' => $plot['commodity'],
                    'planting_start_month' => $plot['planting_start_month'],
                    'planting_end_month' => $plot['planting_end_month'],
                    'size_ha' => $sizeHa,
                    'farm_type' => 'Irrigated',
                    'boundary_points' => $mappedPlot['points'],
                    'geotag_status' => 'mapped',
                    'geotag_assigned_user_id' => null,
                    'geotag_assigned_name' => null,
                    'geotag_priority' => null,
                    'geotag_notes' => null,
                    'geotag_deadline' => null,
                    'remarks' => 'Demo geotagged parcel inside Silauan Norte (Poblacion).',
                ]
            );

            $this->stampCoordinates($plot['id'], $mappedPlot['centroid']['lng'], $mappedPlot['centroid']['lat']);
            $this->syncFarmerArea($plot['farmer_id'], $sizeHa);
        }

        FarmPlot::updateOrCreate(
            ['id' => 'b1000005-0000-4000-8000-000000000005'],
            [
                'farmer_id' => 'a1000005-0000-4000-8000-000000000005',
                'parcel_name' => 'Pending Field Walk',
                'location_brgy' => $brgy,
                'location_city' => 'Echague',
                'location_province' => 'Isabela',
                'latitude' => null,
                'longitude' => null,
                'georef_id' => null,
                'total_parcel_area_ha' => 0.5000,
                'ownership_type' => 'Registered Owner',
                'proof_of_ownership_document' => 'Demo Seeder',
                'commodity' => 'Rice',
                'planting_start_month' => 'June',
                'planting_end_month' => 'October',
                'size_ha' => 0.5000,
                'farm_type' => 'Irrigated',
                'boundary_points' => null,
                'geotag_status' => 'pending_field',
                'geotag_notes' => 'Awaiting technician GPS walk for system demonstration.',
                'remarks' => 'Registered parcel without geotag — demo pending_field state.',
            ]
        );

        $this->clearCoordinates('b1000005-0000-4000-8000-000000000005');
        $this->syncFarmerArea('a1000005-0000-4000-8000-000000000005', 0.5000);
    }

    private function stampCoordinates(string $plotId, float $lng, float $lat): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::update(
            'UPDATE farm_plots SET coordinates = POINT(?, ?) WHERE id = ?',
            [$lng, $lat, $plotId]
        );
    }

    private function clearCoordinates(string $plotId): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::update('UPDATE farm_plots SET coordinates = NULL WHERE id = ?', [$plotId]);
    }

    private function syncFarmerArea(string $farmerId, float $sizeHa): void
    {
        Farmer::query()->where('id', $farmerId)->update([
            'total_farm_area_ha' => $sizeHa,
        ]);
    }
}
