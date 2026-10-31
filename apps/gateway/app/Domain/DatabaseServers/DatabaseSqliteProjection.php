<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the native run; descriptors contain paths and placement, never database bytes. */
final readonly class DatabaseSqliteProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['source', 'target']));
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (data_get($operation->input, 'source.node_id') !== $callerNodeId
            && data_get($operation->input, 'target.node_id') !== $callerNodeId) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node is not an admitted SQLite source or target.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return $this->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code', 'bytes', 'sha256', 'mode', 'state']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
