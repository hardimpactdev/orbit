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
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** A new Valkey index is checked on its server; only an index below the live database count applies the update. */
final readonly class DatabaseConnectionUpdateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null || $operation->kind !== 'database.update' || $operation->target_type !== 'database_connection') {
            throw new ResourceOperationException('database.update_intent_invalid', 'The update belongs to another Connection or Node.', 422);
        }
        $input = $operation->input;
        $server = DatabaseServer::query()->findOrFail(ValidatedData::integer($input['server_id']));
        $auth = ['vault' => 'orbit-control-inputs', 'key' => 'database-update-intent-'.$id];
        if (! $operation->status->terminal()) {
            // AUTH is the first stdin line of the one valkey-cli call, so the admin password goes Gateway → vault → stdin.
            $auth = app(SwampCommandStep::class)->executionCopy($auth['vault'], $auth['key'], "AUTH default {$server->root_password}\nCONFIG GET databases\n");
        }

        return ['intent' => ['container' => ValidatedData::string($input['container']), 'index' => ValidatedData::integer($input['index']),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => ['auth' => $auth]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $result = [];
            if (($data['status'] ?? null) === 'succeeded') {
                // valkey-cli answers OK, then `databases` and the count; the index must be below the count.
                $steps = ValidatedData::object(ValidatedData::object($data['result'] ?? [])['steps'] ?? []);
                $stdout = ValidatedData::nullableString(ValidatedData::object($steps['Check the index fits'] ?? [])['stdout'] ?? null) ?? '';
                $lines = array_values(array_filter(array_map(trim(...), explode("\n", $stdout))));
                $count = count($lines) === 3 && $lines[0] === 'OK' && $lines[1] === 'databases' && ctype_digit($lines[2]) ? (int) $lines[2] : 0;
                $code = $count < 1 ? 'database.server_command_failed'
                    : (ValidatedData::integer($operation->input['index']) >= $count ? 'database.index_invalid' : null);
                $result = $code === null ? ['backend' => 'active'] : ['error_code' => $code];
                if ($code !== null) {
                    $data = [...$data, 'status' => 'failed', 'error_code' => $code, 'failure' => ['failed_step' => 'Check the index fits', 'stderr_recorded' => true]];
                }
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
