<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseConnections\DatabaseConnectionInput;
use App\Models\DatabaseConnection;
use App\Models\Operation;

final readonly class ReadDatabaseConnectionAction
{
    public function __construct(private RecordDatabaseOperationAction $operations) {}

    public function execute(DatabaseConnection $connection, string $mode, string $sql = '', ?string $table = null): Operation
    {
        if (! $connection->driver->supportsInspection() || ! in_array($mode, ['query', 'tables', 'schema', 'describe'], true)) {
            throw new ResourceOperationException('database.driver_unsupported', 'The connection does not support this inspection.', 422);
        }
        if ($connection->driver === DatabaseDriver::Sqlite) {
            $input = app(DatabaseConnectionInput::class)->sqlite($connection, $sql);
            $nodeId = $connection->node_id;
            $input = [...$input, 'password' => '', 'dsn' => '', 'username' => null];
        } else {
            $nodeId = app(NodeAccessAuthorizer::class)->activeGatewayId();
            $input = ['dsn' => app(DatabaseConnectionInput::class)->dsn($connection), 'username' => $connection->username, 'password' => $connection->password ?? '', 'sql' => $sql];
        }
        if ($nodeId === null) {
            throw new ResourceOperationException('database.node_missing', 'The database execution Node is missing.', 422);
        }

        return $this->operations->execute('database.read', 'database_connection', $connection->id, $nodeId,
            ['connection_id' => $connection->id, 'driver' => $connection->driver->value, 'connection_node_id' => $connection->node_id, 'mode' => $mode, 'table' => $table],
            ['database-apply' => ['name' => 'database.read', 'input' => $input, 'protected' => ['password', 'sql', 'dsn', 'username']]]);
    }
}
