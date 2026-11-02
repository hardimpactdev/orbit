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
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
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
        $intent = DatabaseExecutionIntent::recorded($id);
        $input = $intent->payload['commands']['database-apply']['input'];
        $safe = array_intersect_key($input, array_flip(['repair', 'driver', 'username', 'databases', 'test_prefix', 'locale', 'host', 'port', 'container', 'index', 'patterns', 'allow']));

        $safe['stage'] = '/run/orbit-database-create-'.$operation->id;
        $safe['ssh_execution'] = app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id));
        $secrets = ['admin' => ['vault' => 'orbit-control-inputs', 'key' => "database-server-{$operation->target_id}-admin"],
            'owner' => ['vault' => 'orbit-control-inputs', 'key' => 'database-create-intent-'.$intent->id]];
        if (! $operation->status->terminal()) {
            // The server's own admin key (R3); the owner key holds only the new owner's password and salt.
            $secrets['admin'] = DatabaseServerAdminSecret::copy(DatabaseServer::query()->findOrFail($operation->target_id));
            $secrets['owner'] = app(SwampCommandStep::class)->executionCopy($secrets['owner']['vault'], $secrets['owner']['key'], json_encode($input['payload'], JSON_THROW_ON_ERROR));
        }

        return ['intent' => $safe, 'execution_secrets' => $secrets];
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
