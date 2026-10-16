<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\ValkeyAclLock;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;

final readonly class CloseDatabaseServerAction
{
    public function __construct(private ValkeyServerAdmin $admin) {}

    public function execute(DatabaseServer $server): DatabaseServer
    {
        if ($server->driver !== DatabaseDriver::Redis) {
            throw new ResourceOperationException('database.driver_unsupported', 'Only a redis server can close its default user.', 422);
        }

        return ValkeyAclLock::run($server, function () use ($server): DatabaseServer {
            $server->refresh();
            if ($server->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('database.server_inactive', 'The Database server is not active.', 409);
            }
            if (! $server->default_open) {
                return $server;
            }

            if ($server->databaseConnections->contains(static fn (DatabaseConnection $connection): bool => ! filled($connection->password))) {
                throw new ResourceOperationException('database.server_in_use', 'A connection on this server still has no password. Move its clients and destroy the connection first.', 409);
            }

            $this->admin->closeDefaultUser($server);
            $server->update(['default_open' => false]);

            return $server;
        });
    }
}
