<?php

namespace Database\Factories;

use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'host' => 'localhost',
            'user' => 'orbit',
            'port' => 22,
            'is_local' => false,
            'is_default' => false,
            'status' => Environment::STATUS_ACTIVE,
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local',
            'is_local' => true,
            'is_default' => true,
        ]);
    }
}
