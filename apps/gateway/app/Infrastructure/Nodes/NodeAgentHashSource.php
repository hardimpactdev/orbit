<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeEnrollmentPrivateOwner;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleRelocateInput;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class NodeAgentHashSource implements OperationSecretOutputStore, OperationSecretSource
{
    public function name(): string
    {
        return 'node.agent_secret_hash';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['node', 'task_vm', 'task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if (in_array($operation->kind, ['task_vm.provision', 'tasks.provision', 'task_vm.enroll', 'task_vm.prepare_runtime'], true)) {
            return app(NodeEnrollmentPrivateOwner::class)->taskVm($operation, 'secret_hash');
        }
        if (in_array($operation->kind, ['node.role_add', 'node.role_converge'], true)) {
            return NodeRoleDirectInput::ownerId($operation);
        }
        if ($operation->kind === 'node.role_relocate') {
            return NodeRoleRelocateInput::ownerId($operation);
        }
        if ($operation->kind === 'node.agent_rekey' && DirectOperation::owns($operation)
            && $operation->target_type === 'node' && $operation->node_id !== null && $operation->target_id === $operation->node_id) {
            $input = ValidatedData::object($operation->input['_agent_rekey'] ?? []);
            $node = Node::query()->find($operation->node_id);

            return $node !== null && $this->rekeyInput($node, ValidatedData::integer($input['configure_operation_id'] ?? null))
                === array_diff_key($input, ['agent_sha256' => true]) ? $node->id : null;
        }

        return $operation->kind === 'node.converge' && $operation->target_type === 'node'
            && $operation->target_id === $operation->node_id && Node::query()->whereKey($operation->node_id)->exists() ? $operation->node_id : null;
    }

    /** @return array<string, mixed> */
    public function rekeyInput(Node $node, int $sourceId): array
    {
        throw new ResourceOperationException(
            'node.agent_rekey_unavailable',
            'Recovery requires a supported native configure ownership proof.',
            409,
        );
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        if (str_starts_with($operation->kind, 'node.role_')) {
            throw new ResourceOperationException('operation.secret_output_unavailable', 'Role roots cannot store agent hashes.', 409);
        }
        if ($ownerId !== $this->ownerId($operation) || ! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
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
