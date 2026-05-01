<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'store_id' => \App\Models\Store::factory(),
            'is_in_service' => true,
            'role_id' => \App\Models\Role::factory(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'employee_code' => Employee::generateEmployeeCode(),
            'hire_date' => $this->faker->date(),
            'department' => $this->faker->word(),
            'salary' => $this->faker->numberBetween(30000, 100000),
            'manager_id' => null,
            'is_active' => true,
            'avatar' => null,
            'last_login_at' => null,
            'email_verified_at' => now(),
        ];
    }
}