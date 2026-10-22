<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

/** Managed SQL identity changes need a new connection and an explicit consumer move. */
final readonly class PostgresConnectionUpdateHook implements DatabaseConnectionUpdateHook
{
    public function validate(DatabaseConnection $proposed): void
    {
        if ($proposed->server?->driver !== DatabaseDriver::Pgsql) {
            return;
        }
        foreach (['driver', 'node_id', 'host', 'port', 'database', 'locale', 'path', 'username', 'password'] as $field) {
            if ($proposed->{$field} !== $proposed->getOriginal($field)) {
                throw new ResourceOperationException('database.server_connection_managed', 'Create a new managed PostgreSQL connection and move its consumers instead of changing its identity or credentials.', 409);
            }
        }
    }

    public function afterCommit(DatabaseConnection $connection): void {}
}
