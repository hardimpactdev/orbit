<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeTool>
 */
class NodeToolFactory extends Factory
{
    #[\Override]
    public function configure(): static
    {
        return $this->afterMaking(function (NodeTool $tool): void {
            $name = (string) $tool->name;

            if ($name === '') {
                return;
            }

            $tool->instance_key ??= NodeTool::defaultInstanceKey($name);
            $tool->runtime ??= NodeTool::defaultRuntimeForTool($name);
        });
    }

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'name' => 'redis',
            'version_family' => null,
            'runtime_config' => null,
            'expected_state' => 'running',
            'expected_version' => null,
            'config' => [
                'endpoints' => [],
            ],
            'credentials' => null,
        ];
    }
}
