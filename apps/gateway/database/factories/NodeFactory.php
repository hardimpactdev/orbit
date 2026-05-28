<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('node-####'),
            'host' => fake()->unique()->bothify('node-####.test'),
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
        ];
    }

    public function operator(): static
    {
        return $this->state(fn (): array => [
            'tld' => null,
        ]);
    }
}
