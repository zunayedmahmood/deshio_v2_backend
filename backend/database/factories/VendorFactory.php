<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Vendor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'address' => $this->faker->address(),
            'phone' => $this->faker->phoneNumber(),
            'type' => $this->faker->randomElement(['manufacturer', 'distributor', 'wholesaler', 'retailer']),
            'email' => $this->faker->unique()->companyEmail(),
            'contact_person' => $this->faker->name(),
            'website' => $this->faker->url(),
            'credit_limit' => $this->faker->numberBetween(10000, 100000),
            'payment_terms' => $this->faker->randomElement(['net_30', 'net_60', 'net_90', 'cod']),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
            'notes' => $this->faker->sentence(),
        ];
    }

    /**
     * Indicate that the vendor is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a manufacturer vendor.
     */
    public function manufacturer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'manufacturer',
        ]);
    }

    /**
     * Create a distributor vendor.
     */
    public function distributor(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'distributor',
        ]);
    }
}