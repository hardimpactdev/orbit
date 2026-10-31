<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Admission records identities only. Swamp owns every probe, statement and effect. */
final readonly class DropServerDatabaseAction
{
    public function __construct(private RecordDatabaseOperationAction $operations) {}

    public function execute(DatabaseConnection $connection): Operation
    {
        return $this->executeMany([$connection])[0];
    }

    /** @param list<DatabaseConnection> $connections
     * @return list<Operation>
     */
    public function executeMany(array $connections): array
    {
        return DB::transaction(fn (): array => array_map($this->operations->start(...), $this->prepareMany($connections)));
    }

    /** @param list<DatabaseConnection> $connections
     * @return list<PreparedDatabaseOperation>
     */
    public function prepareMany(array $connections, ?int $ownerInstanceId = null): array
    {
        return array_map(fn (array $snapshot): PreparedDatabaseOperation => $this->operations->prepare(
            'database.destroy', 'database_server', $snapshot['server_id'], $snapshot['node_id'],
            ['connection_ids' => $snapshot['connection_ids'], ...$snapshot['input']],
            ['database-apply' => ['name' => 'database.destroy', 'input' => ['password' => $snapshot['password']], 'protected' => ['password']]],
            ['delete_connections' => $snapshot['connection_ids']],
        ), $this->snapshots($connections, $ownerInstanceId));
    }

    /** Protected snapshots also compose into an Instance's single native DAG.
     * @param  list<DatabaseConnection>  $connections
     * @param  list<int>  $ownerInstanceIds
     * @return list<array{server_id:int,node_id:int,connection_ids:list<int>,input:array<string,mixed>,password:string}>
     */
    public function snapshots(array $connections, ?int $ownerInstanceId = null, array $ownerInstanceIds = []): array
    {
        $ownerInstanceIds = $ownerInstanceIds === [] && $ownerInstanceId !== null ? [$ownerInstanceId] : $ownerInstanceIds;
        $groups = [];
        $ids = array_map(static fn (DatabaseConnection $connection): int => $connection->id, $connections);
        foreach ($connections as $connection) {
            $server = $connection->server;
            if (! $server instanceof DatabaseServer) {
                throw new ResourceOperationException('database.server_required', 'Only managed databases have a remote drop.', 422);
            }
            app(DatabaseDestroySafety::class)->assertAcceptsRemoval($server);
            if ($ownerInstanceIds !== [] && ! in_array($connection->owner_instance_id, $ownerInstanceIds, true)) {
                throw new ResourceOperationException('database.ownership_unproven', 'The connection belongs to another Instance.', 409);
            }
            if ($connection->targets()->whereNotIn('instance_id', $ownerInstanceIds)->exists()) {
                throw new ResourceOperationException('database.connection_attached', 'Detach the connection before removal.', 409);
            }
            $redis = $server->driver === DatabaseDriver::Redis;
            $names = $redis || $connection->database === null ? [] : [$connection->database];
            $prefix = ! $redis ? $connection->test_database : null;
            if (! $redis) {
                app(DatabaseDestroySafety::class)->assertNames($names, $prefix === null ? [] : [$prefix]);
            }
            if ($server->driver === DatabaseDriver::Pgsql) {
                $names = [...$names, ...app(PostgresTestDatabases::class)->recordedNames($connection)];
            }
            app(DatabaseDestroySafety::class)->assertNames($names);
            $groups[$server->id] ??= ['server' => $server, 'connection_ids' => [], 'names' => [], 'users' => [], 'prefixes' => [], 'owners' => []];
            $group = &$groups[$server->id];
            $group['connection_ids'][] = $connection->id;
            $group['names'] = [...$group['names'], ...$names];
            foreach ($names as $name) {
                $owner = $connection->username ?? '';
                if (isset($group['owners'][$name]) && $group['owners'][$name] !== $owner) {
                    throw new ResourceOperationException('database.ownership_unproven', 'The database has conflicting recorded owners.', 409);
                }
                $group['owners'][$name] = $owner;
            }
            $group['users'] = [...$group['users'], ...$this->unsharedUsers($connection, $ids)];
            if ($server->driver === DatabaseDriver::Mysql && $prefix !== null) {
                $group['prefixes'][] = $prefix;
                $group['owners'][$prefix] = $connection->username ?? '';
            }
            unset($group);
        }

        return array_values(array_map(static function (array $group): array {
            $server = $group['server'];

            return ['server_id' => $server->id, 'node_id' => $server->node_id, 'connection_ids' => $group['connection_ids'], 'password' => $server->root_password,
                'input' => ['driver' => $server->driver->value,
                    'container' => $server->process === null ? null : app(DockerProcessRenderer::class)->containerName($server->process),
                    'host' => $server->node->wireguard_ip, 'port' => $server->port, 'default_open' => $server->default_open,
                    'databases' => array_values(array_unique($group['names'])), 'users' => array_values(array_unique($group['users'])),
                    'test_prefixes' => array_values(array_unique($group['prefixes'])),
                    'owners' => (object) $group['owners']]];
        }, $groups));
    }

    /** @param list<int> $removingIds
     * @return list<string>
     */
    private function unsharedUsers(DatabaseConnection $connection, array $removingIds): array
    {
        $users = $this->usernames($connection);
        foreach (DatabaseConnection::query()->with('users')->where('database_server_id', $connection->database_server_id)->whereNotIn('id', $removingIds)->get() as $other) {
            $users = array_diff($users, $this->usernames($other));
        }

        return array_values(array_diff(array_unique($users), ['', 'default']));
    }

    /** @return list<string> */
    private function usernames(DatabaseConnection $connection): array
    {
        return [...($connection->username === null ? [] : [$connection->username]), ...$connection->users->map(static fn (DatabaseUser $user): string => $user->username)->values()->all()];
    }
}
