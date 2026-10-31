<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Projects admitted names and domain ownership; never probes or executes on a Node. */
final readonly class DatabaseCreateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        [$intent, $connection] = $this->intent($operation);
        $input = $intent->payload['commands']['database-apply']['input'];
        $safe = array_intersect_key($input, array_flip(['repair', 'driver', 'username', 'databases', 'test_prefix', 'locale', 'host', 'port', 'container', 'index', 'patterns', 'allow']));
        if ($safe['driver'] !== $connection->driver->value || $safe['username'] !== $connection->username
            || $safe['host'] !== $connection->host || $safe['port'] !== $connection->port) {
            $this->invalid();
        }
        if ($safe['driver'] !== 'redis') {
            if ($connection->database === null) {
                $this->invalid();
            }
            $names = [$connection->database, ...($connection->test_database === null ? [] : ($connection->test_processes === null
                ? [$connection->test_database] : app(PostgresTestDatabases::class)->names($connection->test_database, $connection->test_processes)))];
            if ($safe['databases'] !== $names || $safe['locale'] !== $connection->locale
                || $safe['test_prefix'] !== ($safe['driver'] === 'mysql' ? $connection->test_database : null)) {
                $this->invalid();
            }
            app(DatabaseDestroySafety::class)->assertNames($names, $safe['test_prefix'] === null ? [] : [$safe['test_prefix']]);
            $pin = $operation->input['agent_sha256'] ?? null;
            if (! is_string($pin) || preg_match('/\A[0-9a-f]{64}\z/D', $pin) !== 1) {
                $this->invalid();
            }
            $safe['agent_sha256'] = $pin;
        }

        return $safe;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        [$intent, $connection] = $this->intent($operation);
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = array_intersect_key($raw, array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && ($result !== ['backend' => 'active'] || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($code === null || $result !== ['error_code' => $code]
                || ! in_array($code, ['agent.version_mismatch', 'database.server_admin_invalid', 'database.server_admin_failed', 'database.server_not_ready', 'database.name_conflict', 'database.server_command_failed', 'database.index_invalid'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'Database creation requires a bounded Swamp result.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status, $connection, $intent): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'database-create');
            if ($status === 'succeeded' && ! $terminal) {
                if ($connection->driver->value === 'mysql') {
                    $facts = [];
                    foreach ([$connection->database, $connection->test_database] as $name) {
                        if ($name === null) {
                            continue;
                        }
                        $proof = ['database' => $name, 'username' => $connection->username, 'server_id' => $connection->database_server_id];
                        if ($name === $connection->test_database) {
                            $proof['scope'] = 'prefix';
                        }
                        $activity = Activity::query()->create(['request_id' => (string) Str::uuid(), 'command' => 'database:lifecycle:create',
                            'description' => 'Managed database creation confirmed by Swamp.', 'exit_code' => 0,
                            'subject_type' => DatabaseConnection::class, 'subject_id' => $connection->id, 'status' => 'succeeded',
                            'properties' => ['connection_id' => $connection->id, 'node_id' => $operation->node_id, 'receipt' => $proof]]);
                        $facts[] = [...$proof, 'activity_id' => $activity->id];
                    }
                    $connection->forceFill(['creation_receipts' => $facts])->save();
                }
                if (isset($intent->payload['projection']['attach_instance'])) {
                    app(AttachDatabaseConnectionAction::class)->project(Instance::query()->findOrFail($intent->payload['projection']['attach_instance']), $connection, 'DB', replace: true);
                }
                app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseCreated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
            }

            return $projected;
        });
    }

    /** @return array{DatabaseExecutionIntent, DatabaseConnection} */
    private function intent(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($operation->kind !== 'database.create' || $id === null) {
            $this->invalid();
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $connection = DatabaseConnection::query()->find(ValidatedData::integer($operation->input['connection_id'] ?? null));
        $command = $intent->payload['commands']['database-apply'] ?? null;
        if ($connection === null || $connection->database_server_id !== ($operation->input['server_id'] ?? null)
            || $connection->node_id !== $operation->node_id || $connection->server?->node_id !== $operation->node_id
            || ! in_array($operation->target_type, ['database_server', 'instance'], true)
            || ($operation->target_type === 'database_server' && $operation->target_id !== $connection->database_server_id)
            || ($operation->target_type === 'instance' && $operation->target_id !== $connection->owner_instance_id)
            || ($intent->payload['projection']['created_connection'] ?? null) !== $connection->id
            || ($intent->payload['projection']['attach_instance'] ?? null) !== $connection->owner_instance_id
            || ! is_array($command) || count($intent->payload['commands']) !== 1 || $command['name'] !== 'database.create'
            || ($command['protected'] ?? []) !== ['password', 'payload'] || ($intent->payload['projection']['children'] ?? []) !== []) {
            $this->invalid();
        }

        return [$intent, $connection];
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.create_intent_invalid', 'The create does not match its admitted database.', 422);
    }
}
