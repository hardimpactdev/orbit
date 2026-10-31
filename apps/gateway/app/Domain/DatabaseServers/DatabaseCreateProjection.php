<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Public admitted names and record projection; Swamp owns every SQL decision. */
final readonly class DatabaseCreateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null) {
            throw new ResourceOperationException('database.create_intent_invalid', 'The create belongs to another resource or Node.', 422);
        }
        $input = DatabaseExecutionIntent::recorded($id)->payload['commands']['database-apply']['input'];
        $safe = array_intersect_key($input, array_flip(['repair', 'driver', 'username', 'databases', 'test_prefix', 'locale', 'host', 'port', 'container', 'index', 'patterns', 'allow']));

        return $safe;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal) {
                $connection = DatabaseConnection::query()->find(ValidatedData::integer($operation->input['connection_id']));
                if ($connection !== null && ($data['status'] ?? null) === 'succeeded') {
                    if ($connection->owner_instance_id !== null) {
                        app(AttachDatabaseConnectionAction::class)->project(Instance::query()->findOrFail($connection->owner_instance_id), $connection, 'DB', replace: true);
                    }
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseCreated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
                } elseif ($connection !== null && ($data['status'] ?? null) === 'failed' && ($data['error_code'] ?? null) === 'database.index_invalid' && ! $connection->targets()->exists()) {
                    $connection->users()->delete();
                    $connection->delete();
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseDeleted, $connection->id, ['id' => $connection->id, 'slug' => $connection->slug]);
                }
            }

            return $projected;
        });
    }
}
