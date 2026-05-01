<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_type' => $this->faker->randomElement(['counter', 'social_commerce', 'ecommerce']),
            'name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'address' => $this->faker->address(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'postal_code' => $this->faker->postcode(),
            'country' => 'Bangladesh',
            'date_of_birth' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'preferences' => [
                'communication' => ['sms' => true, 'email' => false],
                'shopping' => ['category' => 'clothing']
            ],
            'social_profiles' => [],
            'customer_code' => Customer::generateCustomerCode(),
            'total_purchases' => $this->faker->numberBetween(0, 10000),
            'total_orders' => $this->faker->numberBetween(0, 50),
            'last_purchase_at' => $this->faker->dateTimeBetween('-1 year'),
            'first_purchase_at' => $this->faker->dateTimeBetween('-2 years', '-1 year'),
            'status' => 'active',
            'notes' => $this->faker->sentence(),
            'created_by' => null,
            'assigned_employee_id' => null,
            'email_verified_at' => now(),
        ];
    }

    /**
     * Create a counter customer (no password required).
     */
    public function counter(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_type' => 'counter',
            'password' => null,
        ]);
    }

    /**
     * Create a social commerce customer (no password required).
     */
    public function socialCommerce(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_type' => 'social_commerce',
            'password' => null,
        ]);
    }

    /**
     * Create an e-commerce customer (password required).
     */
    public function ecommerce(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_type' => 'ecommerce',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * Create an inactive customer.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Create a blocked customer.
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blocked',
        ]);
    }
}