<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductBarcode>
 */
class ProductBarcodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'barcode' => 'BC' . $this->faker->unique()->numerify('###########'),
            'product_id' => \App\Models\Product::factory(),
            'batch_id' => \App\Models\ProductBatch::factory(),
            'current_store_id' => \App\Models\Store::factory(),
            'current_status' => $this->faker->randomElement(['in_warehouse', 'in_shop', 'on_display', 'in_transit', 'in_shipment', 'with_customer']),
            'location_metadata' => [],
        ];
    }
}
