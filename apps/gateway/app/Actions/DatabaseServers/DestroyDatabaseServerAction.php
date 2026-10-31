<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseServer;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records Process removal. The named data volume remains on the Node. */
final readonly class DestroyDatabaseServerAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(DatabaseServer $server): Operation
    {
        return DB::transaction(function () use ($server): Operation {
            $server = DatabaseServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            if ($server->databaseConnections()->exists()) {
                throw new ResourceOperationException('database.server_in_use', 'The server still holds databases. Destroy their connections first.', 409);
            }
            $pending = Operation::query()->whereIn('kind', ['database_server.create', 'database_server.destroy'])->where('target_type', 'database_server')
                ->where('target_id', $server->id)->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->value('kind');
            if ($pending !== null) {
                throw new ResourceOperationException('database.server_busy', $pending === 'database_server.create'
                    ? 'The server has an unfinished create intent.' : 'The server has an unfinished removal intent.', 409);
            }
            $process = $server->process;
            $operation = $this->operations->execute('database_server.destroy', 'database_server', $server->id, $server->node_id,
                ['server_id' => $server->id, 'process_id' => $process?->id,
                    'container' => $process === null ? null : (new DockerProcessRenderer)->containerName($process),
                    'agent_sha256' => app(AgentDelivery::class)->pin($server->node)],
                'operator', request()->attributes->getString('orbit.request_id'));
            $server->update(['status' => 'removing']);

            return $operation;
        });
    }
}
