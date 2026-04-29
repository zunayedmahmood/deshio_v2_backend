<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->unique()->word();

        return [
            'title' => ucfirst($title),
            'slug' => $title,
            'description' => $this->faker->sentence(),
            'image' => null,
            'color' => $this->faker->hexColor(),
            'icon' => $this->faker->randomElement(['fas fa-tag', 'fas fa-shopping-bag', 'fas fa-tshirt', 'fas fa-mobile-alt']),
            'order' => $this->faker->numberBetween(1, 100),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
            'parent_id' => null,
            'level' => 1,
            'path' => null,
        ];
    }

    /**
     * Indicate that the category is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a child category.
     */
    public function child(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => Category::factory(),
            'level' => 2,
        ]);
    }

    /**
     * Create a root category.
     */
    public function root(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => null,
            'level' => 1,
        ]);
    }
}