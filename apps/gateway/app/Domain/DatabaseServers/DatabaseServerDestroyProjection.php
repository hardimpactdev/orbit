<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
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
        $recordOnly = $input['record_only'] ?? false;
        if ($operation->kind !== 'database_server.destroy' || $operation->target_type !== 'database_server'
            || ($input['server_id'] ?? null) !== $operation->target_id || ! is_bool($recordOnly)
            || ($id === null ? $container !== null : (! is_int($id) || $id < 1 || ! is_string($container)))) {
            $this->invalid();
        }

        return ['process_id' => $recordOnly ? null : $id, 'container' => $recordOnly ? null : $container];
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
            $input = $operation->input;
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->delete();
                if (! ($input['record_only'] ?? false)) {
                    Process::query()->whereKey($input['process_id'])->delete();
                }
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
