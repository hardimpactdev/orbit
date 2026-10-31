<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSqlClassifier;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseConnections\PdoDatabaseInspector;
use App\Infrastructure\DatabaseConnections\SqliteNodeDatabaseInspector;
use App\Models\DatabaseConnection;
use App\Models\Operation;

final readonly class QueryDatabaseConnectionAction
{
    public function __construct(
        private DatabaseSqlClassifier $classifier,
        private DatabaseInspectionExecutor $executor,
        private RecordDatabaseOperationAction $operations,
    ) {}

    public function execute(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult|Operation
    {
        $sql = $this->classifier->normalize($sql);
        $this->classifier->assertWriteAllowed($sql, $write);

        if (! $write) {
            return $this->executor->query($connection, $sql, false);
        }
        if ($connection->driver === DatabaseDriver::Redis) {
            throw new ResourceOperationException('database.driver_unsupported', 'Redis does not support SQL.', 422);
        }
        if ($connection->driver === DatabaseDriver::Sqlite) {
            $input = app(SqliteNodeDatabaseInspector::class)->writeInput($connection, $sql);
            $nodeId = $connection->node_id;
            $command = ['name' => 'database.write-sqlite', 'input' => [...$input, 'password' => '', 'dsn' => '', 'username' => null], 'protected' => ['password', 'sql', 'dsn', 'username']];
        } else {
            $nodeId = app(NodeAccessAuthorizer::class)->activeGatewayId();
            $command = ['name' => 'database.write', 'input' => [
                'dsn' => app(PdoDatabaseInspector::class)->dsn($connection), 'username' => $connection->username,
                'password' => $connection->password ?? '', 'sql' => $sql,
            ], 'protected' => ['password', 'sql', 'dsn', 'username']];
        }
        if ($nodeId === null) {
            throw new ResourceOperationException('database.node_missing', 'The database execution Node is missing.', 422);
        }

        return $this->operations->execute('database.write', 'database_connection', $connection->id, $nodeId,
            ['connection_id' => $connection->id, 'driver' => $connection->driver->value, 'connection_node_id' => $connection->node_id], ['database-apply' => $command]);
    }
}
