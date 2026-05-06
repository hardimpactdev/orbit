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
            'role' => 'app',
            'host' => fake()->unique()->bothify('node-####.test'),
            'ssh_user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => false,
        ];
    }
}
