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
            'name' => $this->faker->unique()->slug(),
            'role' => 'app',
            'host' => $this->faker->domainName(),
            'ssh_user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'is_local' => false,
        ];
    }
}
