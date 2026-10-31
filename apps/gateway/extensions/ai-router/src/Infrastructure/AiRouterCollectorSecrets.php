<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorSnapshot;

/** Collector-only Node-root adaptation; generic Process authority remains unchanged. */
final readonly class AiRouterCollectorSecrets implements OperationSecretSource
{
    public function __construct(private AiRouterCollectorSnapshot $snapshot) {}

    public function name(): string
    {
        return AiRouterCollectorSnapshot::Source;
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'node.converge' && $operation->target_type === 'node'
            && $operation->node_id === $operation->target_id
            && $operation->node_id === ($operation->input['node_id'] ?? null)
            && Arr::get($operation->input, '_converge.schema_version') === 1
            && Arr::get($operation->input, '_converge.node_id') === $operation->node_id
            && is_string(Arr::get($operation->input, '_converge.agent_sha256'))
            && preg_match('/\A[a-f0-9]{64}\z/D', Arr::get($operation->input, '_converge.agent_sha256')) === 1
            && ($operation->state['node_converge_writer'] ?? null) === 'direct'
            && ! $operation->status->terminal()
            ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== AiRouterCollectorSnapshot::Parameter) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector material does not belong to this root.', 422);
        }
        $payload = Arr::get($operation->input, '_converge.payloads.ai-router');
        if (! is_array($payload) || ($operation->input['collector_installation'] ?? null) !== ($payload['installation'] ?? null)
            || ($payload['schema_version'] ?? null) !== 1 || ($payload['node_id'] ?? null) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector snapshot is missing.', 422);
        }
        $node = Node::query()->findOrFail($id);
        if ($this->snapshot->pin($node) !== Arr::get($operation->input, '_converge.agent_sha256')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector agent changed after admission.', 422);
        }
        $artifacts = Arr::get($operation->input, '_converge.artifacts');
        if (! is_array($artifacts) || ! array_is_list($artifacts)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The footprint applicability is missing.', 422);
        }
        $collector = array_filter($artifacts, static fn (mixed $entry): bool => is_array($entry) && ($entry['name'] ?? null) === 'ai-router');
        if (($payload['applicable'] ?? null) === false && $collector === [] && ! $this->snapshot->applicable($node)
            && $payload === $this->snapshot->capture($node)) {
            return new OperationSecretValue(null);
        }
        if (($payload['applicable'] ?? null) !== true || count($collector) !== 1
            || ! $this->snapshot->applicable($node)
            || Arr::get($operation->input, '_converge.agent_sha256') !== Arr::get($payload, 'process.agent_sha256')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector applicability changed after admission.', 422);
        }

        return $this->snapshot->privateMaterial($node, ValidatedData::object($payload));
    }
}
