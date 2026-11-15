<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleRelocateInput;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class NodeAgentHashSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'node.agent_secret_hash';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if (in_array($operation->kind, ['node.role_add', 'node.role_converge'], true)) {
            return NodeRoleDirectInput::ownerId($operation);
        }
        if ($operation->kind === 'node.role_relocate') {
            return NodeRoleRelocateInput::ownerId($operation);
        }

        return null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $parameter !== 'secret_hash') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The agent hash does not belong to this Node.', 422);
        }
        $hash = Node::query()->findOrFail($id)->agent_secret_hash;

        return new OperationSecretValue(is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/D', $hash) === 1 ? $hash : null);
    }
}
