<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DatabaseSqlClassifier;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Operation;
use Illuminate\Support\Str;

final readonly class QueryDatabaseConnectionAction
{
    public function __construct(
        private DatabaseSqlClassifier $classifier,
        private ReadDatabaseConnectionAction $reads,
        private RecordDatabaseOperationAction $operations,
    ) {}

    public function execute(DatabaseConnection $connection, string $sql, bool $write): Operation
    {
        $sql = $this->classifier->normalize($sql);
        $this->classifier->assertWriteAllowed($sql, $write);

        if (! $write) {
            return $this->reads->execute($connection, 'query', $sql);
        }
        if ($connection->driver === DatabaseDriver::Redis) {
            throw new ResourceOperationException('database.driver_unsupported', 'Redis does not support SQL.', 422);
        }
        // A write runs where a read runs, with the same client and placement.
        [$nodeId, $target] = $this->reads->placement($connection, $sql);

        return $this->operations->execute('database.write', 'database_connection', $connection->id, $nodeId,
            ['connection_id' => $connection->id, 'driver' => $connection->driver->value, 'connection_node_id' => $connection->node_id],
            ['database-apply' => ['name' => 'database.write', 'input' => ['target' => $target, 'null' => Str::random(16),
                'password' => $connection->driver === DatabaseDriver::Sqlite ? '' : ($connection->password ?? ''), 'sql' => $sql],
                'protected' => ['password', 'sql']]]);
    }
}
