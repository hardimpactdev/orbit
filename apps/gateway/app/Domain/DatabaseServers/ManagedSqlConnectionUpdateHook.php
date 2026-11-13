<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

/**
 * Managed SQL identity changes need a new connection and an explicit consumer move. A connection that names another
 * database or user would reach that database's data, or let database:user:create and database:destroy act on an
 * account it does not own.
 */
final readonly class ManagedSqlConnectionUpdateHook implements DatabaseConnectionUpdateHook
{
    public function validate(DatabaseConnection $proposed): void
    {
        if (! in_array($proposed->server?->driver, [DatabaseDriver::Mysql, DatabaseDriver::Pgsql], true)) {
            return;
        }
        foreach (['driver', 'node_id', 'host', 'port', 'database', 'locale', 'path', 'username', 'password'] as $field) {
            if ($proposed->{$field} !== $proposed->getOriginal($field)) {
                throw new ResourceOperationException('database.server_connection_managed', 'Create a new managed connection and move its consumers instead of changing its identity or credentials.', 409);
            }
        }
    }

    public function afterCommit(DatabaseConnection $connection): void {}
}
