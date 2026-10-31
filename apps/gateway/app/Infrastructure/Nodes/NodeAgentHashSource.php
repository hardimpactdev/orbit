<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

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
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
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

    /** Original ownership and immutable safe input; no execution or secret reads.
     * @return array<string,mixed>
     */
    public function rekeyInput(Node $node, int $sourceId): array
    {
        $source = Operation::query()->find($sourceId);
        $run = ValidatedData::object($source->state['executor_runs'] ?? [])['footprint-agent'] ?? [];
        if ($source === null || $source->kind !== 'node.converge' || $source->status->value !== 'running'
            || $source->node_id !== $node->id || $source->target_type !== 'node' || $source->target_id !== $node->id
            || $source->parent_operation_id !== null || $source->lock_key !== 'node:'.$node->id
            || ! is_array($run) || ($run['name'] ?? null) !== 'agent.configure' || ($run['transport'] ?? null) !== 'agent'
            || ($run['started'] ?? null) !== true || ! array_key_exists('result', $run) || $run['result'] !== null
            || app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420)->isLocked()) {
            throw new ResourceOperationException('node.agent_rekey_unavailable', 'Recovery requires the owning open uncertain standalone configure.', 409);
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'configure_operation_id' => $sourceId, 'worker_user' => $node->user,
            'configuration' => array_intersect_key(ValidatedData::object($run['input'] ?? []),
                array_flip(['configuration', 'certificate', 'unit', 'checkouts', 'binary_changed']))];
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
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
