<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Exposes admitted records and records Swamp's result. It performs no Node work. */
final readonly class DatabaseCloneProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
        $input = Arr::except($intent->payload['commands']['database-apply']['input'], ['password', 'payload']);

        return $input;
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (($operation->input['source_node_id'] ?? null) !== $callerNodeId) {
            throw new ResourceOperationException('operation.executor_forbidden', 'Only the admitted clone source may read this intent.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return $this->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($data['status'] === 'succeeded' && ! $terminal) {
                $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
                $projection = $intent->payload['projection'];
                $connection = DatabaseConnection::query()->findOrFail(ValidatedData::integer($projection['created_connection'] ?? null));
                $connection->update(['clone_step' => 'complete']);
                app(AttachDatabaseConnectionAction::class)->project(Instance::query()->findOrFail(ValidatedData::integer($projection['attach_instance'] ?? null)), $connection, 'DB', replace: true);
                app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseCreated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
            }

            return $projected;
        });
    }
}
