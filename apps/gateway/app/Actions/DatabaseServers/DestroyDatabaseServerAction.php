<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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
                    'container' => $process === null ? null : (new DockerProcessRenderer)->containerName($process), 'record_only' => $this->preservesAdoption($server)],
                'operator', request()->attributes->getString('orbit.request_id'));
            $server->update(['status' => 'removing']);

            return $operation;
        });
    }

    private function preservesAdoption(DatabaseServer $server): bool
    {
        $creates = Operation::query()->where('kind', 'database_server.create')->where('target_type', 'database_server')->where('target_id', $server->id);
        if ($server->status === LifecycleStatus::Active || (clone $creates)->where(fn ($query) => $query->where('status', 'succeeded')->orWhere('input->was_active', true))->exists()) {
            return false;
        }
        $create = $creates->latest('id')->first();
        $input = $create->input ?? [];

        return $create?->node_id === $server->node_id
            && ($input['server_id'] ?? null) === $server->id && ($input['process_id'] ?? null) === $server->process_id
            && ($input['validate_existing'] ?? false) === true && ($input['was_active'] ?? null) === false;
    }
}
