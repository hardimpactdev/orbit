<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
        $footprint = $operation->input['footprint'] ?? null;
        if ($operation->kind === 'fleet.recipe' && DirectOperation::owns($operation)
            && $operation->target_type === 'node' && $operation->target_id === $operation->node_id
            && is_array($footprint) && ($footprint['node_id'] ?? null) === $operation->node_id
            && Node::query()->whereKey($operation->node_id)->exists()) {
            return $operation->node_id;
        }

        if ($operation->kind === 'node.agent_rekey' && DirectOperation::owns($operation)
            && $operation->target_type === 'node' && $operation->node_id !== null && $operation->target_id === $operation->node_id) {
            $input = ValidatedData::object($operation->input['_agent_rekey'] ?? []);
            $node = Node::query()->find($operation->node_id);

            return $node !== null && Arr::sortRecursive($this->rekeySourceInput($node, ValidatedData::integer($input['configure_operation_id'] ?? null)))
                === Arr::sortRecursive($input) && app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'secret_hash') === $node->id ? $node->id : null;
        }

        return $operation->kind === 'node.converge' && $operation->target_type === 'node'
            && $operation->target_id === $operation->node_id && Node::query()->whereKey($operation->node_id)->exists() ? $operation->node_id : null;
    }

    /** @return array<string,mixed> Immutable native recovery input; Swamp owns effect serialization. */
    public function rekeyInput(Node $node, int $sourceId): array
    {
        $input = $this->rekeySourceInput($node, $sourceId);
        $source = Operation::query()->findOrFail($sourceId);
        if ($source->status->value === 'running' && $source->result === null) {
            return $input;
        }
        $previous = Operation::query()->where('kind', 'node.agent_rekey')->where('node_id', $node->id)
            ->where('target_type', 'node')->where('target_id', $node->id)->where('input->_agent_rekey->configure_operation_id', $sourceId)
            ->whereNot('status', 'cancelled')->where(static fn ($query) => $query->whereIn('status', ['queued', 'running', 'succeeded'])
            ->orWhere('error_code', 'operation.native_outcome_unknown'))->latest('id')->first();
        if ($previous?->status->value === 'failed' && $previous->error_code === 'operation.native_outcome_unknown'
            && Str::isUuid(ValidatedData::string($previous->state['swamp_run_id'] ?? null))
            && Arr::sortRecursive(ValidatedData::object($previous->input['_agent_rekey'] ?? [])) === Arr::sortRecursive($input)) {
            return $input;
        }
        throw new ResourceOperationException('node.agent_rekey_unavailable', 'Recovery requires an unresolved native converge or its unknown recovery outcome.', 409);
    }

    /** Rechecked during protected storage without revoking custody if the source finishes meanwhile.
     * @return array<string,mixed>
     */
    private function rekeySourceInput(Node $node, int $sourceId): array
    {
        $source = Operation::query()->find($sourceId);
        $snapshot = ValidatedData::object($source?->input['_converge'] ?? []);
        $payloads = ValidatedData::object($snapshot['payloads'] ?? []);
        $agent = ValidatedData::object($payloads['agent'] ?? []);
        $run = $source?->state['swamp_run_id'] ?? null;
        $pin = $snapshot['agent_sha256'] ?? null;
        $artifacts = $snapshot['artifacts'] ?? [];
        if ($source === null || $source->kind !== 'node.converge' || ! DirectOperation::owns($source)
            || $source->node_id !== $node->id || $source->target_type !== 'node' || $source->target_id !== $node->id
            || ($snapshot['schema_version'] ?? null) !== 1 || ($snapshot['node_id'] ?? null) !== $node->id
            || ! is_string($run) || ! Str::isUuid($run)
            || ! is_string($pin) || preg_match('/\A[0-9a-f]{64}\z/D', $pin) !== 1
            || ! is_array($artifacts) || ! array_is_list($artifacts)
            || ! array_any($artifacts, static fn ($entry): bool => is_array($entry) && ($entry['name'] ?? null) === 'agent' && ($entry['required'] ?? null) === true)
            || ($agent['user'] ?? null) !== $node->user || ! is_string($agent['configuration'] ?? null)
            || ! is_string($agent['certificate'] ?? null) || ! is_string($agent['unit'] ?? null)
            || ! is_array($agent['checkouts'] ?? null) || ! array_is_list($agent['checkouts'])
            || array_diff(['apps_root', 'url'], array_keys($agent)) !== []
            || array_intersect(['secret_hash', 'worker_credentials', 'allow_secret_rotation'], array_keys($agent)) !== []) {
            throw new ResourceOperationException('node.agent_rekey_unavailable', 'Recovery requires the owning immutable native agent input.', 409);
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'configure_operation_id' => $sourceId,
            'configure_run_id' => $run, 'worker_user' => $node->user, 'agent_sha256' => $pin,
            'configuration' => array_intersect_key($agent, array_flip(['configuration', 'certificate', 'unit', 'checkouts', 'apps_root']))];
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        if ($operation->kind === 'fleet.recipe' || str_starts_with($operation->kind, 'node.role_') || $operation->status->terminal() || $ownerId !== $this->ownerId($operation) || ! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new ResourceOperationException('operation.secret_output_unavailable', 'The agent hash must belong to the admitted Node.', 409);
        }
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
