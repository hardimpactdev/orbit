<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\ValkeyAclLock;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use Closure;

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
        private ValkeyServerAdmin $valkey,
        private PostgresTestDatabases $postgresTests,
    ) {}

    /** Keep the record removal inside the ACL lock when the caller removes the connection too. */
    public function execute(DatabaseConnection $connection, ?Closure $afterDrop = null): void
    {
        $server = $connection->server;
        if (! $server instanceof DatabaseServer || $server->driver !== DatabaseDriver::Redis) {
            $this->executeMany([$connection], static fn (DatabaseConnection $dropped) => $afterDrop?->__invoke());

            return;
        }

        ValkeyAclLock::run($server, function () use ($connection, $afterDrop): void {
            $current = $connection->fresh(['server', 'users']);
            if ($current === null) {
                return;
            }
            $this->dropValkey($current);
            $afterDrop?->__invoke();
        });
    }

    /**
     * Preflight every server before any drop. Drop all planned databases before their users.
     *
     * @param  list<DatabaseConnection>  $connections
     * @param  Closure(DatabaseConnection): mixed  $afterDrop
     */
    public function executeMany(array $connections, Closure $afterDrop): void
    {
        $ids = array_map(static fn (DatabaseConnection $connection): int => $connection->id, $connections);
        $plans = [];
        foreach ($connections as $connection) {
            $server = $connection->server;
            if (! $server instanceof DatabaseServer || $server->driver === DatabaseDriver::Redis) {
                continue;
            }
            $key = $server->id;
            $plans[$key] ??= ['server' => $server, 'databases' => [], 'users' => []];
            $plans[$key]['databases'] = [...$plans[$key]['databases'], ...$this->databases($connection, $server)];
            $plans[$key]['users'] = [...$plans[$key]['users'], ...$this->unsharedUsers($connection, $server, $ids)];
        }
        foreach ($plans as $plan) {
            $blockers = $this->admin->execute($plan['server'], $this->statements->forDriver($plan['server']->driver)->removalBlockers(
                array_values(array_unique($plan['databases'])), array_values(array_unique($plan['users'])),
            ));
            if ($blockers !== []) {
                throw new ResourceOperationException(
                    'database.in_use',
                    'Database removal is blocked by clients or dependencies on ['.implode(', ', $blockers).']. Stop clients or remove the dependencies and retry.',
                    409,
                    details: ['blockers' => $blockers],
                );
            }
        }
        foreach ($plans as $plan) {
            $sql = '';
            $statements = $this->statements->forDriver($plan['server']->driver);
            foreach (array_unique($plan['databases']) as $database) {
                $sql .= $statements->dropDatabase($database);
            }
            foreach (array_unique($plan['users']) as $username) {
                $sql .= $statements->dropUser($username);
            }
            if ($sql !== '') {
                $this->admin->execute($plan['server'], $sql);
            }
        }
        foreach ($connections as $connection) {
            if ($connection->server?->driver === DatabaseDriver::Redis) {
                $this->execute($connection, static fn () => $afterDrop($connection));
            } else {
                $afterDrop($connection);
            }
        }
    }

    private function dropValkey(DatabaseConnection $connection): void
    {
        $server = $connection->server;
        if (! $server instanceof DatabaseServer) {
            return;
        }
        foreach ($this->unsharedUsers($connection, $server) as $username) {
            if ($username !== '' && $username !== ValkeyAclUser::ADMIN) {
                $this->valkey->deleteUser($server, $username);
            }
        }
    }

    /** @return list<string> */
    private function databases(DatabaseConnection $connection, DatabaseServer $server): array
    {
        $databases = is_string($connection->database) ? [$connection->database] : [];

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
