<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Validates the recorded edge. The received hook deletes it atomically with the run receipt. */
final readonly class RemoveNodeAccessEdgeCommand implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $consumer = $operation->input['consumer_node_id'] ?? null;
        if ($operation->kind !== 'node.access_remove' || $operation->target_type !== 'node'
            || $operation->target_id !== $operation->node_id || ! is_int($consumer) || $consumer < 1 || $input !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The recorded access edge is invalid.', 422);
        }

        return ['access_removed' => true];
    }
}
