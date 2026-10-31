<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admitted container identity and atomic record projection. No Node execution. */
final readonly class DatabaseServerDestroyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $id = $input['process_id'] ?? null;
        $container = $input['container'] ?? null;
        if ($operation->kind !== 'database_server.destroy' || $operation->target_type !== 'database_server'
            || ($input['server_id'] ?? null) !== $operation->target_id
            || ! is_string($input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input['agent_sha256']) !== 1
            || ($id === null ? $container !== null : (! is_int($id) || $id < 1 || ! is_string($container)))) {
            $this->invalid();
        }
        if (! $operation->status->terminal()) {
            $server = DatabaseServer::query()->find($operation->target_id);
            $process = $server?->process;
            if ($server === null || $server->node_id !== $operation->node_id || $server->process_id !== $id
                || ($id !== null && ($process === null || $process->owner_type !== Node::class || $process->owner_id !== $operation->node_id
                    || $process->runtime->value !== 'docker' || (new DockerProcessRenderer)->containerName($process) !== $container))) {
                $this->invalid();
            }
            if ($server->databaseConnections()->exists()) {
                throw new ResourceOperationException('database.server_in_use', 'The server still holds databases.', 409);
            }
        }

        return ['process_id' => $id, 'container' => $container, 'agent_sha256' => $input['agent_sha256']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && ($result !== ['backend' => 'absent'] || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($result !== ['error_code' => $code]
                || ! in_array($code, ['agent.version_mismatch', 'process.invalid', 'process.name_collision', 'process.converge_failed', 'process.remove_failed'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'Server removal needs its bounded Swamp result.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $input = $this->input($operation);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->delete();
                Process::query()->whereKey($input['process_id'])->delete();
            } elseif (! $terminal && in_array($status, ['failed', 'cancelled'], true)) {
                DatabaseServer::query()->whereKey($operation->target_id)->update([
                    'status' => 'failed', 'error_code' => $data['error_code'] ?? null, 'failed_step' => 'database-apply',
                ]);
            }

            return $projected;
        });
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_intent_invalid', 'Server removal does not match its admitted Node and Process.', 422);
    }
}
