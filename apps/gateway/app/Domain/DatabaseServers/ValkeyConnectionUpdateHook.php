<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use Illuminate\Validation\ValidationException;

/** Keeps a managed Valkey connection's identity and credentials aligned with its ACL user. */
final readonly class ValkeyConnectionUpdateHook implements DatabaseConnectionUpdateHook
{
    public function __construct(private ValkeyServerAdmin $admin) {}

    public function validate(DatabaseConnection $proposed): void
    {
        $server = $proposed->server;
        if ($server?->driver !== DatabaseDriver::Redis) {
            return;
        }

        foreach (['driver', 'node_id', 'host', 'port', 'path', 'username', 'password'] as $field) {
            if ($proposed->{$field} !== $proposed->getOriginal($field)) {
                throw new ResourceOperationException(
                    errorCode: 'database.server_connection_managed',
                    message: 'Create a new Valkey connection and move its clients instead of changing its identity or credentials.',
                    status: 409,
                );
            }
        }

        if ($proposed->isDirty('database') && $proposed->database !== null && (! ctype_digit($proposed->database) || (int) $proposed->database >= $this->admin->databaseCount($server))) {
            throw ValidationException::withMessages(['database' => ['The database index is outside the range configured on this server.']]);
        }
    }

    public function afterCommit(DatabaseConnection $connection): void {}
}
