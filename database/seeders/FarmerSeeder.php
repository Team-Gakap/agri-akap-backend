<?php

namespace Database\Seeders;

use App\Models\Farmer;
use Database\Seeders\Concerns\SilauanNorteDemoPolygons;
use Illuminate\Database\Seeder;

/**
 * Five demo farmers in Silauan Norte (Poblacion) for Spatial Inspector walkthroughs.
 * IDs stay stable so FarmPlotSeeder can re-run idempotently.
 * RSBSA numbers use the 901–905 block so they do not collide with live sequential
 * registrations (IV-02-0423-{YEAR}-{SEQ}).
 */
class FarmerSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->farmers() as $farmer) {
            Farmer::updateOrCreate(
                ['id' => $farmer['id']],
                $farmer
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function farmers(): array
    {
        $brgy = SilauanNorteDemoPolygons::BARANGAY;
        $now = now();

        return [
            [
                'id' => 'a1000001-0000-4000-8000-000000000001',
                'rsbsa_no' => 'IV-02-0423-2026-901',
                'transaction_code' => 't1000001-0000-4000-8000-000000000001',
                'qr_code_hash' => 'q1000001-0000-4000-8000-000000000001',
                'surname' => 'Dela Cruz',
                'first_name' => 'Juan',
                'middle_name' => 'Mercado',
                'sex' => 'Male',
                'permanent_house_no' => '12',
                'permanent_street' => 'Purok 1',
                'permanent_brgy' => $brgy,
                'permanent_city' => 'Echague',
                'permanent_province' => 'Isabela',
                'permanent_region' => 'Region II',
                'birthdate' => '1978-05-12',
                'mobile_number' => '09171230001',
                'mothers_maiden_first_name' => 'Rosa',
                'mothers_maiden_surname' => 'Mercado',
                'civil_status' => 'Married',
                'spouse_first_name' => 'Elena',
                'spouse_surname' => 'Dela Cruz',
                'highest_education' => 'High School non K-12',
                'livelihood_type' => 'Farmer',
                'livelihood_detail' => 'Rice',
                'verification_status' => 'approved',
                'verified_at' => $now,
            ],
            [
                'id' => 'a1000002-0000-4000-8000-000000000002',
                'rsbsa_no' => 'IV-02-0423-2026-902',
                'transaction_code' => 't1000002-0000-4000-8000-000000000002',
                'qr_code_hash' => 'q1000002-0000-4000-8000-000000000002',
                'surname' => 'Santos',
                'first_name' => 'Maria',
                'middle_name' => 'Luz',
                'sex' => 'Female',
                'permanent_house_no' => '8',
                'permanent_street' => 'Purok 2',
                'permanent_brgy' => $brgy,
                'permanent_city' => 'Echague',
                'permanent_province' => 'Isabela',
                'permanent_region' => 'Region II',
                'birthdate' => '1984-11-03',
                'mobile_number' => '09171230002',
                'mothers_maiden_first_name' => 'Carmen',
                'mothers_maiden_surname' => 'Luz',
                'civil_status' => 'Married',
                'spouse_first_name' => 'Jose',
                'spouse_surname' => 'Santos',
                'highest_education' => 'College',
                'livelihood_type' => 'Farmer',
                'livelihood_detail' => 'Corn',
                'verification_status' => 'approved',
                'verified_at' => $now,
            ],
            [
                'id' => 'a1000003-0000-4000-8000-000000000003',
                'rsbsa_no' => 'IV-02-0423-2026-903',
                'transaction_code' => 't1000003-0000-4000-8000-000000000003',
                'qr_code_hash' => 'q1000003-0000-4000-8000-000000000003',
                'surname' => 'Reyes',
                'first_name' => 'Pedro',
                'middle_name' => 'Alonzo',
                'sex' => 'Male',
                'permanent_house_no' => '21',
                'permanent_street' => 'Sitio Centro',
                'permanent_brgy' => $brgy,
                'permanent_city' => 'Echague',
                'permanent_province' => 'Isabela',
                'permanent_region' => 'Region II',
                'birthdate' => '1969-02-18',
                'mobile_number' => '09171230003',
                'mothers_maiden_first_name' => 'Teresa',
                'mothers_maiden_surname' => 'Alonzo',
                'civil_status' => 'Married',
                'spouse_first_name' => 'Lorna',
                'spouse_surname' => 'Reyes',
                'highest_education' => 'Elementary',
                'livelihood_type' => 'Farmer',
                'livelihood_detail' => 'Rice',
                'verification_status' => 'approved',
                'verified_at' => $now,
            ],
            [
                'id' => 'a1000004-0000-4000-8000-000000000004',
                'rsbsa_no' => 'IV-02-0423-2026-904',
                'transaction_code' => 't1000004-0000-4000-8000-000000000004',
                'qr_code_hash' => 'q1000004-0000-4000-8000-000000000004',
                'surname' => 'Bautista',
                'first_name' => 'Ana',
                'middle_name' => 'Cristina',
                'sex' => 'Female',
                'permanent_house_no' => '5',
                'permanent_street' => 'Purok 3',
                'permanent_brgy' => $brgy,
                'permanent_city' => 'Echague',
                'permanent_province' => 'Isabela',
                'permanent_region' => 'Region II',
                'birthdate' => '1990-07-22',
                'mobile_number' => '09171230004',
                'mothers_maiden_first_name' => 'Imelda',
                'mothers_maiden_surname' => 'Cristina',
                'civil_status' => 'Single',
                'highest_education' => 'Senior High School K-12',
                'livelihood_type' => 'Farmer',
                'livelihood_detail' => 'Vegetables',
                'verification_status' => 'approved',
                'verified_at' => $now,
            ],
            [
                'id' => 'a1000005-0000-4000-8000-000000000005',
                'rsbsa_no' => 'IV-02-0423-2026-905',
                'transaction_code' => 't1000005-0000-4000-8000-000000000005',
                'qr_code_hash' => 'q1000005-0000-4000-8000-000000000005',
                'surname' => 'Garcia',
                'first_name' => 'Roberto',
                'middle_name' => 'Manalo',
                'sex' => 'Male',
                'permanent_house_no' => '17',
                'permanent_street' => 'Purok 4',
                'permanent_brgy' => $brgy,
                'permanent_city' => 'Echague',
                'permanent_province' => 'Isabela',
                'permanent_region' => 'Region II',
                'birthdate' => '1975-09-09',
                'mobile_number' => '09171230005',
                'mothers_maiden_first_name' => 'Gloria',
                'mothers_maiden_surname' => 'Manalo',
                'civil_status' => 'Married',
                'spouse_first_name' => 'Marites',
                'spouse_surname' => 'Garcia',
                'highest_education' => 'Vocational',
                'livelihood_type' => 'Farmer',
                'livelihood_detail' => 'Rice',
                'verification_status' => 'approved',
                'verified_at' => $now,
            ],
        ];
    }
}
