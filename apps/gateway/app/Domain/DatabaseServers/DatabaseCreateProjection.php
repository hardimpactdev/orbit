<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
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
        $input = ValidatedData::object($intent->payload['commands']['database-apply']['input']);
        $payload = ValidatedData::object($input['payload']);
        $driver = DatabaseDriver::from(ValidatedData::string($input['driver']));
        $user = ValidatedData::string($input['username']);
        $databases = ValidatedData::stringList($input['databases']);
        $statements = app(DatabaseStatements::class)->create(['driver' => $driver->value, 'username' => $user, 'databases' => $databases,
            'test_prefix' => ValidatedData::nullableString($input['test_prefix']), 'locale' => ValidatedData::nullableString($input['locale']),
            'index' => is_int($input['index'] ?? null) ? $input['index'] : null,
            'patterns' => ValidatedData::stringList($input['patterns']), 'allow' => ValidatedData::stringList($input['allow'])],
            ValidatedData::string($payload['password']), ValidatedData::string($payload['salt']));
        $container = ValidatedData::string($input['container']);
        $login = "/run/orbit-database-create-{$operation->id}.env";
        $ownerLogin = "/run/orbit-database-create-{$operation->id}-owner.env";
        $secrets = ['statement' => ['vault' => 'orbit-control-inputs', 'key' => "database-create-intent-{$id}"],
            'owner_env' => ['vault' => 'orbit-control-inputs', 'key' => "database-create-owner-{$id}"]];
        if ($driver !== DatabaseDriver::Pgsql) {
            $secrets['client_env'] = ['vault' => 'orbit-control-inputs', 'key' => "database-server-{$operation->target_id}-client-env"];
        }
        if (! $operation->status->terminal()) {
            // The create statement carries the owner's password, so it goes Gateway → vault → stdin (Q-DBAUTH).
            $copy = app(SwampCommandStep::class);
            $secrets['statement'] = $copy->executionCopy('orbit-control-inputs', "database-create-intent-{$id}", $statements['create']);
            $secrets['owner_env'] = $copy->executionCopy('orbit-control-inputs', "database-create-owner-{$id}", $statements['owner_env']);
            if ($driver !== DatabaseDriver::Pgsql) {
                $secrets['client_env'] = DatabaseServerAdminSecret::clientEnvCopy($this->server($operation));
            }
        }

        return ['intent' => ['driver' => $driver->value, 'username' => $user, 'container' => $container, 'login' => $login, 'owner_login' => $ownerLogin,
            'index' => is_int($input['index'] ?? null) ? $input['index'] : null,
            'client' => DatabaseStatements::client($driver, $container, $login),
            'owner_client' => DatabaseStatements::ownerClient($driver, $container, $ownerLogin, $user, $databases[0] ?? 'postgres'),
            'statements' => ['probe' => $statements['probe'], 'login' => $statements['login']],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => $secrets];
    }

    private function server(Operation $operation): DatabaseServer
    {
        return DatabaseServer::query()->findOrFail($operation->target_id);
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
