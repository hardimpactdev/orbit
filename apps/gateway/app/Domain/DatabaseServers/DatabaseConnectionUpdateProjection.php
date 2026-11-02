<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Proposed credentials stay encrypted; only a successful native result applies the update. */
final readonly class DatabaseConnectionUpdateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null || $operation->kind !== 'database.update' || $operation->target_type !== 'database_connection') {
            throw new ResourceOperationException('database.update_intent_invalid', 'The update belongs to another Connection or Node.', 422);
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $input = $intent->payload['commands']['database-apply']['input'];
        $copies = [];
        if ($input['validate_index']) {
            $copy = ['vault' => 'orbit-control-inputs', 'key' => 'database-update-intent-'.$intent->id];
            if (! $operation->status->terminal()) {
                $copy = app(SwampCommandStep::class)->executionCopy($copy['vault'], $copy['key'], json_encode(['password' => $input['password']], JSON_THROW_ON_ERROR));
            }
            $copies['connection_update'] = $copy;
        }

        return ['intent' => array_intersect_key($input, array_flip(['validate_index', 'host', 'port', 'index', 'ssh'])), 'execution_secrets' => $copies];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = [];
            if (($raw['backend'] ?? null) === 'active') {
                $result['backend'] = 'active';
            }
            if (in_array($raw['error_code'] ?? null, ['database.index_invalid', 'database.server_command_failed'], true)) {
                $result['error_code'] = $raw['error_code'];
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && ($data['status'] ?? null) === 'succeeded') {
                $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
                $connection = DatabaseConnection::query()->findOrFail($operation->target_id);
                $connection->update(ValidatedData::object($intent->payload['projection']['connection_update'] ?? null));
                DB::afterCommit(static function () use ($connection): void {
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseUpdated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
                });
            }

            return $projected;
        });
    }
}
