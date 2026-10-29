<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Models\Operation;

/** Prepares the complete SQL removal set. Lifecycle composes and awaits the resulting Operations. */
final readonly class DropOwnedDatabasesAction
{
    public function __construct(private DropServerDatabaseAction $serverDatabases) {}

    /** @return list<Operation> */
    public function execute(int $instanceId): array
    {
        $connections = DatabaseConnection::query()->with(['server', 'users'])
            ->where('owner_instance_id', $instanceId)->whereNotNull('database_server_id')->orderBy('id')->get();

        return $this->serverDatabases->executeMany(array_values($connections->all()));
    }

    public function drop(DatabaseConnection $connection): Operation
    {
        return $this->serverDatabases->execute($connection);
    }
}
