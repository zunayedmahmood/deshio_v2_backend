<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Role::class;

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
            'guard_name' => 'web',
            'level' => $this->faker->numberBetween(1, 10),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
            'is_default' => false,
        ];
    }

    /**
     * Indicate that the role is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the role is a default role.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Create an admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Administrator',
            'slug' => 'admin',
            'level' => 10,
            'is_default' => false,
        ]);
    }

    /**
     * Create a manager role.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Manager',
            'slug' => 'manager',
            'level' => 5,
            'is_default' => false,
        ]);
    }

    /**
     * Create a staff role.
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Staff',
            'slug' => 'staff',
            'level' => 1,
            'is_default' => true,
        ]);
    }
}