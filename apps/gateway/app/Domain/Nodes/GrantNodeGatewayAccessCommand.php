<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** The received hook records the grant together with its successful run receipt. */
final readonly class GrantNodeGatewayAccessCommand implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        if (($intent['role'] ?? null) !== RoleName::Gateway->value
            || ! $node->roles()->where('role', RoleName::Gateway)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node does not hold the admitted Gateway role.', 422);
        }

        return ['access_granted' => true];
    }
}
