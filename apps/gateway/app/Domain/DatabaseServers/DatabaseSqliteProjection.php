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
        $input = array_intersect_key($operation->input, array_flip(['source', 'target']));
        if ($operation->kind !== 'database.sqlite_seed_abandon') {
            return $input;
        }
        $source = ValidatedData::object($input['source'] ?? []);
        $target = ValidatedData::object($input['target'] ?? []);
        if ($operation->target_type !== 'instance' || ($operation->input['owner_instance_id'] ?? null) !== $operation->target_id
            || ($operation->input['source_instance_id'] ?? null) !== $operation->target_id || ($source['variant'] ?? null) !== 'seed' || ($source['node_id'] ?? null) === ($target['node_id'] ?? null)
            || ($target['node_id'] ?? null) !== $operation->node_id || ($target['target_instance_id'] ?? null) !== $operation->target_id
            || ($source['target_instance_id'] ?? null) !== $operation->target_id || ($target['environment'] ?? null) !== 'development'
            || ($source['operation'] ?? null) !== ($target['operation'] ?? null)
            || ! is_string($source['operation'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $source['operation']) !== 1
            || in_array($source['node_id'] ?? null, [1, 18], true) || in_array($target['node_id'] ?? null, [1, 18], true)) {
            throw new ResourceOperationException('sqlite.seed_abandon_forbidden', 'The seed cleanup needs its admitted source and target workers.', 422);
        }

        return ['intent' => $input, 'execution_secrets' => []];
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
