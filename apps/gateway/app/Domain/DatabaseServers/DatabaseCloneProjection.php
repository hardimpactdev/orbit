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
use App\Infrastructure\Swamp\SwampCommandStep;
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
        $intent = $this->intent($operation);
        $raw = $intent->payload['commands']['database-apply']['input'];
        $input = Arr::except($raw, ['password', 'payload']);
        $copies = [];
        if (($input['variant'] ?? null) === 'mysql') {
            $copy = ['vault' => 'orbit-control-inputs', 'key' => 'database-clone-intent-'.$intent->id];
            if (! $operation->status->terminal()) {
                $copy = app(SwampCommandStep::class)->executionCopy($copy['vault'], $copy['key'],
                    json_encode(array_intersect_key($raw, array_flip(['password', 'payload'])), JSON_THROW_ON_ERROR));
            }
            $copies['database_clone'] = $copy;
        }

        return ['intent' => $input, 'execution_secrets' => $copies];
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

        $intent = $this->intent($operation);

        return Arr::except($intent->payload['commands']['database-apply']['input'], ['password', 'payload']);
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

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null || $operation->kind !== 'database.clone' || $operation->target_type !== 'instance') {
            throw new ResourceOperationException('database.clone_intent_invalid', 'The clone does not match its admitted target.', 422);
        }

        return DatabaseExecutionIntent::recorded($id);
    }
}
