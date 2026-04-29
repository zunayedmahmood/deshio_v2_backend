<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => \App\Models\Category::factory(),
            'vendor_id' => \App\Models\Vendor::factory(),
            'brand' => $this->faker->randomElement(['Nike', 'Adidas', 'Puma', 'Reebok', 'New Balance', 'Under Armour', 'Converse', 'Vans', 'Fila', 'Champion']),
            'sku' => $this->faker->unique()->regexify('[A-Z]{3}[0-9]{6}'),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),
            'is_archived' => false,
        ];
    }

    /**
     * Indicate that the product is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_archived' => true,
        ]);
    }

    /**
     * Create a product with a specific category.
     */
    public function forCategory(\App\Models\Category $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    /**
     * Create a product with a specific vendor.
     */
    public function forVendor(\App\Models\Vendor $vendor): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => $vendor->id,
        ]);
    }
}