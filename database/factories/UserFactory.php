<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'technician',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['role' => 'super_admin', 'is_active' => true]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin', 'is_active' => true]);
    }

    public function technician(): static
    {
        return $this->state(fn () => ['role' => 'technician', 'is_active' => true]);
    }

    public function barangayOfficial(string $barangay = 'San Fabian'): static
    {
        return $this->state(fn () => [
            'role' => 'barangay_official',
            'assigned_barangay' => $barangay,
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
