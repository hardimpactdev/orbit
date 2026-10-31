<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
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
        $input = DatabaseExecutionIntent::recorded($id)->payload['commands']['database-apply']['input'];

        return array_intersect_key($input, array_flip(['validate_index', 'host', 'port', 'index']));
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
