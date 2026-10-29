<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () use ($operation): void {
            $node = Node::query()->lockForUpdate()->findOrFail($operation->node_id);
            $settings = $node->settings ?? [];
            $attempt = $settings['machine_recipe_attempt'] ?? null;
            if (is_array($attempt) && ($attempt['operation_id'] ?? null) === $operation->id) {
                return;
            }
            $settings['machine_recipe_attempt'] = ['operation_id' => $operation->id,
                'identity' => $operation->input['identity'], 'snapshot' => $operation->input['snapshot'],
                'status' => 'unverified', 'started_at' => now()->toIso8601String()];
            $node->update(['settings' => $settings]);
        });

        return ['started' => true];
    }
}
