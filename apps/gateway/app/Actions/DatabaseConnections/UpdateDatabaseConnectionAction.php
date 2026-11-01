<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

final readonly class UpdateDatabaseConnectionAction
{
    /** @param iterable<DatabaseConnectionUpdateHook> $hooks */
    public function __construct(
        #[Tag(DatabaseConnectionUpdateHook::class)] private iterable $hooks,
        private RecordDatabaseOperationAction $operations,
    ) {}

    public function execute(DatabaseConnection $connection, UpdateDatabaseConnectionData $data): Operation
    {
        return DB::transaction(function () use ($connection, $data): Operation {
            $locked = DatabaseConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $attributes = DatabaseConnectionProfile::fromUpdate($locked, $data)->attributes();
            unset($attributes['slug']);

            if (! $data->passwordProvided) {
                unset($attributes['password']);
            }

            $proposed = clone $locked;
            $proposed->fill($attributes);

            if ($proposed->driver === DatabaseDriver::Sqlite) {
                foreach ($locked->targets()->with('instance')->get() as $target) {
                    if ($target->instance->node_id !== $proposed->node_id) {
                        throw new ResourceOperationException(
                            errorCode: 'database.node_mismatch',
                            message: 'A SQLite connection must be on the same Node as every attached Instance.',
                            status: 422,
                        );
                    }
                }
            }

            foreach ($this->hooks as $hook) {
                $hook->validate($proposed);
            }
            $server = $locked->server;
            $validateIndex = $server instanceof DatabaseServer && $server->driver === DatabaseDriver::Redis && $proposed->isDirty('database') && $proposed->database !== null;
            $nodeId = $server instanceof DatabaseServer ? $server->node_id : app(NodeAccessAuthorizer::class)->activeGatewayId();
            if ($nodeId === null) {
                throw new ResourceOperationException('database.node_unavailable', 'A worker Node is required for this update.', 422);
            }
            $node = Node::query()->findOrFail($nodeId);
            $ssh = ['host' => $node->wireguard_ip, 'user' => $node->user,
                'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key) ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []];
            $input = $validateIndex
                ? ['validate_index' => true, 'host' => $server->node->wireguard_ip, 'port' => $server->port, 'index' => $proposed->database, 'ssh' => $ssh]
                : ['validate_index' => false, 'ssh' => $ssh];

            return $this->operations->execute('database.update', 'database_connection', $locked->id, $nodeId,
                [], ['database-apply' => ['name' => 'database.update', 'input' => [...$input, 'password' => $validateIndex ? $server->root_password : ''], 'protected' => ['password']]],
                ['connection_update' => $this->patch($attributes, $data)]);
        });
    }

    /** @param array<string, int|string|null> $attributes
     * @return array<string, int|string|null>
     */
    private function patch(array $attributes, UpdateDatabaseConnectionData $data): array
    {
        $provided = [
            'driver' => $data->driverProvided, 'node_id' => $data->nodeIdProvided,
            'host' => $data->hostProvided, 'port' => $data->portProvided,
            'database' => $data->databaseProvided, 'path' => $data->pathProvided,
            'username' => $data->usernameProvided, 'password' => $data->passwordProvided,
        ];
        if ($data->driverProvided) {
            foreach (['host', 'port', 'database', 'path'] as $field) {
                $provided[$field] = true;
            }
        }

        return array_intersect_key($attributes, array_filter($provided));
    }
}
