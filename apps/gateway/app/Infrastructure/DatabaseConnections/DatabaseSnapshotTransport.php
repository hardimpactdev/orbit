<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Operations\OperationTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Reuses the protected binary relay. The only mutation targets Operation.node_id; source access is a read. */
final readonly class DatabaseSnapshotTransport implements OperationTransport
{
    public function __construct(private SqliteSnapshotTransfer $transfer) {}

    public function schema(Operation $operation, array $run): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['source_instance_id', 'target_instance_id', 'source_path', 'target_path', 'bytes', 'sha256'],
            'properties' => ['source_instance_id' => ['type' => 'integer', 'minimum' => 1], 'target_instance_id' => ['type' => 'integer', 'minimum' => 1],
                'source_path' => ['type' => 'string'], 'source_node_id' => ['type' => 'integer'],
                'source_base' => ['type' => 'string'], 'source_user' => ['type' => 'string'], 'source_environment' => ['type' => 'string'],
                'target_base' => ['type' => 'string'], 'target_user' => ['type' => 'string'], 'target_environment' => ['type' => 'string'], 'target_path' => ['type' => 'string'], 'bytes' => ['type' => 'integer', 'minimum' => 16],
                'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$']]];
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        if ($run['name'] !== 'database.receive-snapshot') {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not a database snapshot receive.', 422);
        }
        if ($operation->target_type !== 'instance' || ($input['target_instance_id'] ?? null) !== $operation->target_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The snapshot target does not belong to the Operation.', 422);
        }
        $source = Instance::query()->with('node')->findOrFail(ValidatedData::integer($input['source_instance_id']));
        $target = Instance::query()->with('node')->findOrFail(ValidatedData::integer($input['target_instance_id']));
        if ((! isset($input['target_base']) && $target->node_id !== $operation->node_id) || $source->project_id !== $target->project_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The snapshot target does not belong to the Operation.', 422);
        }
        $placement = static fn (Instance $instance): SqliteSeedPlacement => new SqliteSeedPlacement(
            instanceId: $instance->id, environment: 'development', basePath: $instance->checkout_path, executionUser: $instance->node->user, node: $instance->node,
        );
        $sourcePlacement = isset($input['source_base']) ? new SqliteSeedPlacement($source->id, ValidatedData::string($input['source_environment']), ValidatedData::string($input['source_base']), ValidatedData::string($input['source_user']), Node::query()->findOrFail(ValidatedData::integer($input['source_node_id']))) : $placement($source);
        $targetPlacement = isset($input['target_base']) ? new SqliteSeedPlacement($target->id, ValidatedData::string($input['target_environment']), ValidatedData::string($input['target_base']), ValidatedData::string($input['target_user']), Node::query()->findOrFail(ValidatedData::integer($operation->node_id))) : $placement($target);
        $this->transfer->transfer($sourcePlacement, ValidatedData::string($input['source_path']), $targetPlacement, ValidatedData::string($input['target_path']), ValidatedData::integer($input['bytes']), ValidatedData::string($input['sha256']));

        return ['backend' => 'active', 'bytes' => $input['bytes'], 'sha256' => ValidatedData::string($input['sha256'])];
    }
}
