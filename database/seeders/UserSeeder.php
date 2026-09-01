<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'superadmin@mao.com'],
            [
                'name' => 'System SuperAdmin',
                'password' => Hash::make('password123'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );

        // 1. Create the Default System Administrator
        User::updateOrCreate(
            ['email' => 'admin@mao.com'],
            [
                'name' => 'MAO Administrator',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // 2. Create a Default Field Technician for Mobile App testing
        User::updateOrCreate(
            ['email' => 'john@mao.com'],
            [
                'name' => 'John',
                'password' => Hash::make('password123'),
                'role' => 'technician',
                'is_active' => true,
            ]
        );

        // 3. Create a Barangay Official who pre-assesses disaster damage
        User::updateOrCreate(
            ['email' => 'brgy@mao.com'],
            [
                'name' => 'Barangay Official',
                'password' => Hash::make('password123'),
                'role' => 'barangay_official',
                'assigned_barangay' => 'Silauan Norte (Poblacion)',
                'is_active' => true,
            ]
        );

        // 4. Create a Deactivated User to test UI login restrictions
        User::updateOrCreate(
            ['email' => 'suspended@mao.com'],
            [
                'name' => 'Suspended Tech',
                'password' => Hash::make('password123'),
                'role' => 'technician',
                'is_active' => false,
            ]
        );

        // Note: Because we are using the User model, our HasUuid trait
        // is automatically generating the 36-character UUID primary keys behind the scenes!
    }
}
