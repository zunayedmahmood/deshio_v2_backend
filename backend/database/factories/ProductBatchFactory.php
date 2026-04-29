<?php

namespace Database\Factories;

use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductBatch>
 */
class ProductBatchFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ProductBatch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $costPrice = $this->faker->numberBetween(50, 500);
        $sellPrice = $costPrice + $this->faker->numberBetween(10, 100);

        return [
            'product_id' => \App\Models\Product::factory(),
            'batch_number' => $this->faker->unique()->regexify('BATCH-[0-9]{8}'),
            'quantity' => $this->faker->numberBetween(10, 1000),
            'cost_price' => $costPrice,
            'sell_price' => $sellPrice,
            'availability' => $this->faker->boolean(80), // 80% chance of being available
            'manufactured_date' => $this->faker->dateTimeBetween('-2 years', '-1 month'),
            'expiry_date' => $this->faker->dateTimeBetween('+6 months', '+2 years'),
            'store_id' => \App\Models\Store::factory(),
            'barcode_id' => null,
            'notes' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Create a batch with specific quantity.
     */
    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    /**
     * Create an out of stock batch.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 0,
        ]);
    }

    /**
     * Create a batch for a specific product.
     */
    public function forProduct(\App\Models\Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
        ]);
    }

    /**
     * Create a batch for a specific store.
     */
    public function forStore(\App\Models\Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }
}