<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseServerCloseProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        $intent = $id === null ? null : DatabaseExecutionIntent::recorded($id);
        $command = $intent?->payload['commands']['database-apply'] ?? [];
        $input = $command['input'] ?? [];
        if ($operation->kind !== 'database_server.close' || $operation->target_type !== 'database_server'
            || $intent === null
            || ! is_bool($input['default_open'] ?? null) || ($input['action'] ?? null) !== ($input['default_open'] ? 'close' : 'ready')) {
            throw new ResourceOperationException('database.server_intent_invalid', 'Closure does not match its admitted Database server.', 422);
        }

        return ['host' => $input['host'], 'port' => $input['port'], 'default_open' => $input['default_open']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && ($result !== ['backend' => 'active'] || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($result !== ['error_code' => $code]
                || ! in_array($code, ['database.server_in_use', 'database.server_command_failed'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'Closure needs its bounded native result.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->update(['default_open' => false]);
            }

            return $projected;
        });
    }
}
