<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Store;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default store if it doesn't exist
        $defaultStore = Store::firstOrCreate(
            ['store_code' => 'MAIN'],
            [
                'name' => 'Main Store',
                'address' => '123 Main Street, City, Country',
                'phone' => '+1234567890',
                'email' => 'main@deshioerp.com',
                'contact_person' => 'Store Manager',
                'store_code' => 'MAIN',
                'description' => 'Main headquarters store',
                'is_warehouse' => true,
                'is_online' => true,
                'is_active' => true,
                'opening_hours' => json_encode([
                    'monday' => ['open' => '09:00', 'close' => '18:00'],
                    'tuesday' => ['open' => '09:00', 'close' => '18:00'],
                    'wednesday' => ['open' => '09:00', 'close' => '18:00'],
                    'thursday' => ['open' => '09:00', 'close' => '18:00'],
                    'friday' => ['open' => '09:00', 'close' => '18:00'],
                    'saturday' => ['open' => '09:00', 'close' => '16:00'],
                    'sunday' => ['open' => '10:00', 'close' => '14:00'],
                ]),
            ]
        );

        // Get the superadmin role
        $superAdminRole = \App\Models\Role::where('slug', 'super-admin')->first();

        if (!$superAdminRole) {
            $this->command->error('Super Admin role not found. Please run RolesSeeder first.');
            return;
        }

        // Create Super Admin Employee
        $superAdmin = Employee::firstOrCreate(
            ['email' => 'mueedibnesami.anoy@gmail.com'],
            [
                'name' => 'Mueed Sami',
                'email' => 'mueedibnesami.anoy@gmail.com',
                'password' => Hash::make('12345678'),
                'store_id' => $defaultStore->id,
                'is_in_service' => true,
                'role_id' => $superAdminRole->id,
                'phone' => '+1234567890',
                'address' => '123 Admin Street, Admin City, Admin Country',
                'employee_code' => 'EMP-001',
                'hire_date' => now()->toDateString(),
                'department' => 'Administration',
                'salary' => 100000.00,
                'manager_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Created Super Admin: ' . $superAdmin->name);
        $this->command->info('Email: ' . $superAdmin->email);
        $this->command->info('Default Store: ' . $defaultStore->name);
    }
}
