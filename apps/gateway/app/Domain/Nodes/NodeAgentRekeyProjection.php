<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Bounded projection only. Recovery sequencing and validation belong to Swamp. */
final readonly class NodeAgentRekeyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        if (! $operation->status->terminal() && app(NodeAgentHashSource::class)->ownerId($operation) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The recovery source no longer owns this Node.', 422);
        }

        return ValidatedData::object($operation->input['_agent_rekey'] ?? []);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $code = $data['error_code'] ?? null;
        $result = match ($data['status']) {
            'succeeded' => ['rekeyed' => true, 'restarted' => true],
            'failed' => ['rekeyed' => false, 'error_code' => $code],
            default => [],
        };
        $raw = ValidatedData::object($data['result'] ?? []);
        if (count($raw) !== count($result) || array_any($result, static fn ($value, $key) => ($raw[$key] ?? null) !== $value)
            || ($data['status'] === 'failed' ? ! in_array($code, ['agent.version_mismatch', 'agent.update_busy', 'node.agent_rekey_unavailable'], true) : $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'Only a bounded native recovery outcome is accepted.', 422);
        }

        return app(DirectOperation::class)->project($operation, $data, $result, 'node-agent-rekey');
    }
}
