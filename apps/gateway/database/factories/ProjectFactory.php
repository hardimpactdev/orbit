<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
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
            'runtime' => AppRuntimeKind::Php,
            'runtime_config' => null,
            'adopted' => false,
            'agent_ide_config' => null,
        ];
    }

    public function static(): static
    {
        return $this->state(fn (): array => [
            'runtime' => AppRuntimeKind::Static,
        ]);
    }
}
