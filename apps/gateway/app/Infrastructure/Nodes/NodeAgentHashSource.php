<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class NodeAgentHashSource implements OperationSecretOutputStore, OperationSecretSource
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
        if (in_array($operation->kind, ['node.role_add', 'node.role_remove'], true)) {
            return NodeRoleDirectInput::ownerId($operation);
        }

        return $operation->kind === 'node.converge' && $operation->target_type === 'node'
            && $operation->target_id === $operation->node_id && Node::query()->whereKey($operation->node_id)->exists() ? $operation->node_id : null;
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        if (in_array($operation->kind, ['node.role_add', 'node.role_remove'], true)
            || $ownerId !== $this->ownerId($operation) || ! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new ResourceOperationException('operation.secret_output_unavailable', 'The agent hash must belong to the admitted Node.', 409);
        }
        app(NodeLocks::class)->renewHeld();
        Node::query()->findOrFail($ownerId)->forceFill(['agent_secret_hash' => $value])->save();
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
