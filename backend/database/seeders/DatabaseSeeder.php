<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Core system seeders
            PermissionsSeeder::class,
            RolesSeeder::class,
            EmployeesSeeder::class,

            // Business data seeders
            PaymentMethodsSeeder::class,
            AccountsSeeder::class,
            ExpenseCategoriesSeeder::class,
            ExpensesSeeder::class,
        ]);
    }
}
