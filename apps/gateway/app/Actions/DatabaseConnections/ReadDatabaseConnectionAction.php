<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseRead;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseConnections\DatabaseConnectionInput;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\Operation;
use Illuminate\Support\Str;

final readonly class ReadDatabaseConnectionAction
{
    public function __construct(private RecordDatabaseOperationAction $operations) {}

    /**
     * Record one read. It runs where the database is: in the Database server's container, as the file's owner on the
     * SQLite Node, or from 209 for a registered external database.
     */
    public function execute(DatabaseConnection $connection, string $mode, string $sql = '', ?string $table = null): Operation
    {
        if (! $connection->driver->supportsInspection() || ! in_array($mode, ['query', 'tables', 'schema', 'describe'], true)) {
            throw new ResourceOperationException('database.driver_unsupported', 'The connection does not support this inspection.', 422);
        }
        if ($mode === 'query') {
            DatabaseRead::assertReadable($connection->driver, $sql);
        }
        $server = $connection->server;
        if ($connection->driver === DatabaseDriver::Sqlite) {
            $input = app(DatabaseConnectionInput::class)->sqlite($connection, $sql);
            [$nodeId, $target] = [$connection->node_id, ['owner' => $input['owner'], 'path' => (string) $input['path']]];
        } elseif ($server !== null) {
            $process = $server->process ?? throw new ResourceOperationException('database.server_command_failed', 'The server has no Process.', 502);
            [$nodeId, $target] = [$server->node_id, ['container' => app(DockerProcessRenderer::class)->containerName($process),
                'user' => (string) $connection->username, 'database' => (string) $connection->database]];
        } else {
            [$nodeId, $target] = [app(SwampSshExecutionInput::class)->identity()['control_node_id'], ['host' => (string) $connection->host,
                'port' => $connection->port ?? ($connection->driver === DatabaseDriver::Pgsql ? 5432 : 3306),
                'user' => (string) $connection->username, 'database' => (string) $connection->database]];
        }
        if ($nodeId === null) {
            throw new ResourceOperationException('database.node_missing', 'The database execution Node is missing.', 422);
        }

        return $this->operations->execute('database.read', 'database_connection', $connection->id, $nodeId,
            ['connection_id' => $connection->id, 'driver' => $connection->driver->value, 'connection_node_id' => $connection->node_id, 'mode' => $mode, 'table' => $table],
            ['database-apply' => ['name' => 'database.read', 'input' => ['target' => $target, 'null' => Str::random(32),
                'password' => $connection->driver === DatabaseDriver::Sqlite ? '' : ($connection->password ?? ''), 'sql' => $sql],
                'protected' => ['password', 'sql']]]);
    }
}
