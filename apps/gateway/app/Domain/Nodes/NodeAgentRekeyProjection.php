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
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []),
            array_flip(['rekeyed', 'restarted', 'error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
