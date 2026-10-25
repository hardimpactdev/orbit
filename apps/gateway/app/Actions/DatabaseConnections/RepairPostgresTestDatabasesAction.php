<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
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
    ) {}

    public function execute(DatabaseServer $server, DatabaseConnection $connection): DatabaseConnection
    {
        return $this->lock->run([$server->id], function () use ($server, $connection): DatabaseConnection {
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

            $present = $this->admin->execute($server, $this->statements->databasesIn($names));
            foreach ($names as $name) {
                $create = in_array($name, $present, true)
                    ? ''
                    : $this->statements->createDatabase($name, $connection->locale, $username);
                $this->admin->execute($server,
                    $create.$this->statements->grantAll($name, $username));
            }

            return $connection;
        });
    }
}
