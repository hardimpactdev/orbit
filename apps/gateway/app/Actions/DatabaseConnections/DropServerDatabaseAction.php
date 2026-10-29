<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Drops the database behind a connection on a Database server: the database, its test databases,
 * and each user Orbit created for it that no other connection on the server still uses. Every
 * statement tolerates a missing object, so a repeated drop succeeds.
 *
 * On a Valkey server it deletes only the connection's ACL user, never its keys. A connection that
 * logs in as `default`, such as one linked by adoption, keeps the admin user.
 */
final readonly class DropServerDatabaseAction
{
    public function __construct(
        private DatabaseServerAdmin $admin,
        private MysqlStatements $statements,
        private PostgresTestDatabases $postgresTests,
        private DatabaseRemovalLock $removalLock,
        private RecordDatabaseOperationAction $operations,
        private DatabaseOwnership $ownership,
    ) {}

    public function execute(DatabaseConnection $connection): Operation
    {
        return $this->executeMany([$connection])[0];
    }

    /** Preflight the full set before admitting any drop.
     * @param  list<DatabaseConnection>  $connections
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
        $serverIds = array_values(array_filter(array_map(static fn (DatabaseConnection $connection): ?int => $connection->database_server_id, $connections)));

        return $this->removalLock->run($serverIds, fn (): array => $this->prepareLocked($connections, $ownerInstanceId));
    }

    /** @param list<DatabaseConnection> $connections
     * @return list<PreparedDatabaseOperation>
     */
    private function prepareLocked(array $connections, ?int $ownerInstanceId): array
    {
        $groups = [];
        $ids = array_map(static fn (DatabaseConnection $connection): int => $connection->id, $connections);
        foreach ($connections as $connection) {
            $server = $connection->server;
            if (! $server instanceof DatabaseServer) {
                throw new ResourceOperationException('database.server_required', 'Only managed databases have a remote drop.', 422);
            }
            $groups[$server->id] ??= ['server' => $server, 'connections' => [], 'databases' => [], 'users' => [], 'owners' => [], 'by_connection' => []];
            $groups[$server->id]['connections'][] = $connection->id;
            $names = $this->databases($connection, $server);
            if ($server->driver === DatabaseDriver::Mysql) {
                $this->ownership->assertMysqlNames($connection, $names);
            }
            $groups[$server->id]['by_connection'][$connection->id] = $names;
            foreach ($names as $name) {
                $groups[$server->id]['owners'][$name] = $connection->username ?? '';
            }
            $groups[$server->id]['databases'] = [...$groups[$server->id]['databases'], ...$names];
            $groups[$server->id]['users'] = [...$groups[$server->id]['users'], ...$this->unsharedUsers($connection, $server, $ids)];
        }
        foreach ($groups as $group) {
            if ($group['server']->driver !== DatabaseDriver::Redis) {
                $this->assertNoBlockers($group['server'], array_values(array_unique($group['databases'])), array_values(array_unique($group['users'])));
            }
        }
        $operations = [];
        foreach ($groups as $group) {
            $server = $group['server'];
            $databases = array_values(array_unique($group['databases']));
            $users = array_values(array_unique($group['users']));
            if ($server->driver === DatabaseDriver::Redis) {
                $command = DatabaseCommands::valkey($server, 'remove', ['usernames' => array_values(array_diff($users, ['default', '']))], 'absent');
            } else {
                $sql = $server->driver === DatabaseDriver::Pgsql
                    ? (new PostgresStatements)->dropFencedDatabases($databases, $users, $group['owners'])
                    : implode('', array_map($this->statements->dropDatabase(...), $databases)).implode('', array_map($this->statements->dropUser(...), $users));
                $command = DatabaseCommands::sql($server, $sql, 'absent', expectEmpty: true, refusal: 'database.in_use');
                $command['transport'] = 'database_removal';
            }
            $command['finish_after_effect'] = true;
            $commands = ['database-apply' => $command];
            if ($server->driver === DatabaseDriver::Pgsql) {
                $commands['restore-admission'] = [...DatabaseCommands::sql($server, (new PostgresStatements)->restoreDatabaseAdmission($databases)), 'cleanup' => true];
            }
            $operations[] = $this->operations->prepare('database.destroy', 'database_server', $server->id, $server->node_id,
                ['owner_instance_id' => $ownerInstanceId, 'connection_id' => $group['connections'][0], 'connection_ids' => $group['connections'], 'driver' => $server->driver->value],
                $commands, ['delete_connections' => $group['connections'], 'drop_users' => $users, 'drop_databases' => $group['by_connection']]);
        }

        return $operations;
    }

    public function assertUsersStillUnshared(Operation $operation, DatabaseExecutionIntent $intent): void
    {
        if (($operation->input['driver'] ?? null) !== 'mysql') {
            return;
        }
        $ids = ValidatedData::integerList($operation->input['connection_ids'] ?? []);
        $connections = DatabaseConnection::query()->whereIn('id', $ids)->with('users', 'server')->get();
        $current = [];
        foreach ($connections as $connection) {
            $server = $connection->server;
            if (! $server instanceof DatabaseServer) {
                throw new ResourceOperationException('database.server_required', 'The removal server is missing.', 409);
            }
            $current = [...$current, ...$this->unsharedUsers($connection, $server, $ids)];
        }
        if (array_diff($intent->payload['projection']['drop_users'] ?? [], $current) !== []) {
            throw new ResourceOperationException('database.user_shared', 'A planned removal user now belongs to another connection.', 409);
        }
    }

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $users
     */
    private function assertNoBlockers(DatabaseServer $server, array $databases, array $users): void
    {
        $blockers = $this->admin->execute($server, $this->statements->forDriver($server->driver)->removalBlockers($databases, $users));
        $this->refuseBlockers($blockers);
    }

    /** @param list<string> $blockers */
    private function refuseBlockers(array $blockers): void
    {
        if ($blockers !== []) {
            throw new ResourceOperationException(
                'database.in_use',
                'Database removal is blocked by clients or dependencies on ['.implode(', ', $blockers).']. Stop clients or remove the dependencies and retry.',
                409,
                details: ['blockers' => $blockers],
            );
        }
    }

    /** @return list<string> */
    private function databases(DatabaseConnection $connection, DatabaseServer $server): array
    {
        $databases = is_string($connection->database) ? [$connection->database] : [];

        if ($server->driver === DatabaseDriver::Redis) {
            return [];
        }

        if ($server->driver === DatabaseDriver::Pgsql && $connection->test_database !== null && $connection->test_processes !== null) {
            $databases = [...$databases, ...$this->postgresTests->names($connection->test_database, $connection->test_processes)];
        } elseif (is_string($connection->test_database) && is_string($connection->database)) {
            $databases = [
                ...$databases,
                ...$this->admin->execute(
                    $server,
                    $this->statements->forDriver($server->driver)->databasesNamed($connection->test_database, $connection->test_database),
                ),
            ];
        }

        return array_values(array_unique($databases));
    }

    /**
     * @param  list<int>  $removingIds
     * @return list<string>
     */
    private function unsharedUsers(DatabaseConnection $connection, DatabaseServer $server, array $removingIds = []): array
    {
        $usernames = $this->usernames($connection);
        $shared = [];

        $others = DatabaseConnection::query()
            ->with('users')
            ->where('database_server_id', $server->id)
            ->whereNotIn('id', [$connection->id, ...$removingIds])
            ->get();

        foreach ($others as $other) {
            $shared = [...$shared, ...$this->usernames($other)];
        }

        return array_values(array_diff(array_unique($usernames), $shared));
    }

    /** @return list<string> */
    private function usernames(DatabaseConnection $connection): array
    {
        $usernames = is_string($connection->username) ? [$connection->username] : [];

        foreach ($connection->users as $user) {
            $usernames[] = $user->username;
        }

        return $usernames;
    }
}
