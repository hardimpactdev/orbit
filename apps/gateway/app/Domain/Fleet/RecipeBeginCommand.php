<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Node;
use App\Models\Operation;

/** Marks the Node as potentially changed immediately before Swamp starts applying the recipe. */
final readonly class RecipeBeginCommand implements OperationGatewayCommand
{
    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    /** @param array<string, mixed> $input
     * @return array{started: true}
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);
        $settings = $node->settings ?? [];
        unset($settings['machine_recipe']);
        $node->update(['settings' => $settings]);

        return ['started' => true];
    }
}
