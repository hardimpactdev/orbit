<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'node_id' => Node::factory(),
            'environment' => 'development',
            'domain' => null,
            'path' => '/home/orbit/apps/'.fake()->unique()->slug(2),
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
        ];
    }
}
