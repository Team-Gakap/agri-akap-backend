<?php

namespace Database\Factories;

use App\Models\Farmer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Farmer>
 */
class FarmerFactory extends Factory
{
    protected $model = Farmer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rsbsa_no' => 'IV-02-0423-'.fake()->unique()->numerify('2026-#####'),
            'transaction_code' => (string) Str::uuid(),
            'photo_path' => null,
            'qr_code_hash' => (string) Str::uuid(),
            'surname' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'sex' => fake()->randomElement(['Male', 'Female']),
            'permanent_house_no' => (string) fake()->buildingNumber(),
            'permanent_street' => fake()->streetName(),
            'permanent_brgy' => 'San Fabian',
            'permanent_city' => 'Echague',
            'permanent_province' => 'Isabela',
            'permanent_region' => 'Region II',
            'birthdate' => fake()->dateTimeBetween('-70 years', '-20 years')->format('Y-m-d'),
            'mobile_number' => '09'.fake()->numerify('#########'),
            'mothers_maiden_first_name' => fake()->firstName('female'),
            'mothers_maiden_surname' => fake()->lastName(),
            'civil_status' => 'Single',
            'highest_education' => 'High School non K-12',
            'livelihood_type' => 'Farmer',
        ];
    }

    public function inBarangay(string $barangay): static
    {
        return $this->state(fn () => ['permanent_brgy' => $barangay]);
    }

    public function withPhoto(string $path = 'farmer-photos/existing.jpg'): static
    {
        return $this->state(fn () => ['photo_path' => $path]);
    }
}
