<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;

/** Restore recorded test databases and converge their managed grants without replacing data. */
final readonly class RepairPostgresTestDatabasesAction
{
    public function __construct(
        private DatabaseServerAdmin $admin,
        private PostgresStatements $statements,
        private PostgresTestDatabases $tests,
        private DatabaseRemovalLock $lock,
        private RecordDatabaseOperationAction $operations,
    ) {}

    public function prepare(DatabaseServer $server, DatabaseConnection $connection): PreparedDatabaseOperation
    {
        return $this->lock->run([$server->id], fn (): PreparedDatabaseOperation => $this->prepareLocked($server, $connection));
    }

    /** Caller holds the server removal lock. */
    public function prepareLocked(DatabaseServer $server, DatabaseConnection $connection): PreparedDatabaseOperation
    {
        $connection->refresh();
        $names = $this->tests->recordedNames($connection);
        $username = $connection->username;
        if ($names === [] || $username === null
            || $this->admin->execute($server, $this->statements->userNamed($username)) !== [$username]
            || $this->admin->execute($server, $this->statements->databasesNotOwnedBy($names, $username)) !== []) {
            throw new ResourceOperationException(
                'database.name_conflict',
                "Database connection [{$connection->slug}] has a missing managed role or a test database with a foreign owner. Nothing was restored.",
                409,
            );
        }

        $commands = [];
        foreach ($names as $index => $name) {
            $sql = DatabaseCommands::createSqlDatabase($server, $name, $username, $connection->locale).$this->statements->grantAll($name, $username);
            $commands[$index === array_key_last($names) ? 'database-apply' : 'test-pool-'.$index] = DatabaseCommands::sql($server, $sql);
        }

        return $this->operations->prepare('database.create', 'database_server', $server->id, $server->node_id,
            ['connection_id' => $connection->id, 'driver' => 'pgsql'], $commands);
    }
}
