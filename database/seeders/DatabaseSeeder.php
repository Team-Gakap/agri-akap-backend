<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    public function run(): void
    {
        $this->call([
            BarangayCoordinateSeeder::class,
            UserSeeder::class,
            BarangayUserSeeder::class,
            FarmerSeeder::class,
            FarmPlotSeeder::class,
        ]);
    }
}
