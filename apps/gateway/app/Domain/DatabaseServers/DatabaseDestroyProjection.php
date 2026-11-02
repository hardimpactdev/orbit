<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDestroyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        app(DatabaseDestroySafety::class)->assertNames(ValidatedData::stringList($input['databases'] ?? []), ValidatedData::stringList($input['test_prefixes'] ?? []));
        if (app(DatabaseIntentSource::class)->ownerId($operation) === null) {
            throw new ResourceOperationException('database.destroy_intent_invalid', 'The removal belongs to another resource or Node.', 422);
        }
        $server = DatabaseServer::query()->findOrFail($operation->target_id);
        $driver = DatabaseDriver::from(ValidatedData::string($input['driver']));
        $login = "/run/orbit-database-destroy-{$operation->id}.env";
        $secrets = [];
        if ($driver !== DatabaseDriver::Pgsql) {
            $secrets['client_env'] = $operation->status->terminal()
                ? ['vault' => 'orbit-control-inputs', 'key' => "database-server-{$server->id}-client-env"]
                : DatabaseServerAdminSecret::clientEnvCopy($server);
        }

        return ['intent' => ['driver' => $driver->value, 'container' => ValidatedData::string($input['container']), 'login' => $login,
            'client' => DatabaseStatements::client($driver, ValidatedData::string($input['container']), $login),
            'statements' => ValidatedData::object($input['statements']),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code'])));
            if (! $terminal && $data['status'] === 'succeeded') {
                foreach (ValidatedData::integerList($operation->input['connection_ids'] ?? []) as $id) {
                    $connection = DatabaseConnection::query()->find($id);
                    if ($connection === null) {
                        continue;
                    }
                    DatabaseConnectionTarget::query()->where('database_connection_id', $id)->delete();
                    $connection->delete();
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseDeleted, $id, ['id' => $id, 'slug' => $connection->slug]);
                }
            }

            return $projected;
        });
    }
}
