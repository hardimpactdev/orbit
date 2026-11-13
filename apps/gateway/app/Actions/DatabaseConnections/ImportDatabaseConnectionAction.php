<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseImport;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\Operation;

/** database:import: admit a dump on the server's Node for an owned MySQL or PostgreSQL database (docs/cli/database/import.mdx). */
final readonly class ImportDatabaseConnectionAction
{
    public function __construct(private RecordDatabaseOperationAction $operations) {}

    /** The route needs Gateway authority, as every database command does; that authority reaches the server's Node too. */
    public function execute(DatabaseConnection $connection, string $file): Operation
    {
        $server = $connection->server;
        $process = $server?->process;
        if (! in_array($connection->driver, [DatabaseDriver::Mysql, DatabaseDriver::Pgsql], true) || $server === null || $process === null
            || $server->status !== LifecycleStatus::Active || ! is_string($connection->username) || ! is_string($connection->database)
            || ! is_string($connection->password) || $connection->password === '') {
            throw new ResourceOperationException('database.import_unsupported', 'The connection is not a MySQL or PostgreSQL database on an active Database server.', 422);
        }
        $image = $process->runtime_config['image'] ?? null;
        $argv = DatabaseImport::argv($connection->driver, $file, app(DockerProcessRenderer::class)->containerName($process),
            is_string($image) ? $image : '', $connection->username, $connection->database);

        return $this->operations->execute('database.import', 'database_connection', $connection->id, $server->node_id,
            ['connection_id' => $connection->id, 'driver' => $connection->driver->value, 'file' => DatabaseImport::path($file)],
            ['database-apply' => ['name' => 'database.import', 'input' => ['argv' => $argv, 'password' => $connection->password],
                'protected' => ['password']]]);
    }
}
