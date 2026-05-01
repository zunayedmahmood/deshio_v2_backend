<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => $this->faker->randomElement(['shipping', 'billing', 'both']),
            'name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'address_line_1' => $this->faker->streetAddress(),
            'address_line_2' => $this->faker->optional()->secondaryAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'postal_code' => $this->faker->postcode(),
            'country' => 'Bangladesh',
            'landmark' => $this->faker->optional()->sentence(),
            'is_default_shipping' => false,
            'is_default_billing' => false,
            'delivery_instructions' => $this->faker->optional()->sentence(),
        ];
    }
}
