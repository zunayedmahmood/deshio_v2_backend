<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => \App\Models\Customer::factory(),
            'store_id' => \App\Models\Store::factory(),
            'order_type' => $this->faker->randomElement(['counter', 'social_commerce', 'ecommerce']),
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => $this->faker->randomElement(['cash', 'card', 'bank_transfer']),
            'subtotal' => 1000.00,
            'tax_amount' => 50.00,
            'discount_amount' => 0.00,
            'shipping_amount' => 60.00,
            'total_amount' => 1110.00,
            'order_date' => now(),
        ];
    }
}
