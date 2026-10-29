<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseServer;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records Process removal. The named data volume remains on the Node. */
final readonly class DestroyDatabaseServerAction
{
    public function __construct(private RecordDatabaseOperationAction $operations) {}

    public function execute(DatabaseServer $server): Operation
    {
        if ($server->databaseConnections()->exists()) {
            throw new ResourceOperationException('database.server_in_use', 'The server still holds databases. Destroy their connections first.', 409);
        }
        $process = $server->process;

        return DB::transaction(function () use ($server, $process): Operation {
            $operation = $this->operations->execute('database_server.destroy', 'database_server', $server->id, $server->node_id,
                ['server_id' => $server->id, 'driver' => $server->driver->value, 'variant' => $process === null ? 'record_only' : 'process'],
                ['database-apply' => $process === null ? ['transport' => 'gateway', 'name' => 'database.server-absent', 'input' => [], 'backend' => 'absent'] : ['name' => 'process.remove', 'input' => ['process_id' => $process->id,
                    'runtime' => 'docker', 'name' => (new DockerProcessRenderer)->containerName($process)], 'backend' => 'absent']],
                ['delete_server' => true, 'process_id' => $process?->id]);
            $server->update(['status' => 'removing']);

            return $operation;
        });
    }
}
