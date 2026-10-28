<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Operation;

final readonly class CloseDatabaseServerAction
{
    public function __construct(private RecordDatabaseOperationAction $operations) {}

    public function execute(DatabaseServer $server): Operation
    {
        if ($server->driver !== DatabaseDriver::Redis) {
            throw new ResourceOperationException('database.driver_unsupported', 'Only a redis server can close its default user.', 422);
        }
        if ($server->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('database.server_inactive', 'The Database server is not active.', 409);
        }
        if ($server->databaseConnections->contains(static fn (DatabaseConnection $connection): bool => ! filled($connection->password))) {
            throw new ResourceOperationException('database.server_in_use', 'A connection still has no password. Move its clients first.', 409);
        }

        return $this->operations->execute('database_server.close', 'database_server', $server->id, $server->node_id,
            ['server_id' => $server->id, 'driver' => 'redis'],
            ['database-apply' => DatabaseCommands::valkey($server, $server->default_open ? 'close' : 'ready')], ['server' => ['default_open' => false]]);
    }
}
