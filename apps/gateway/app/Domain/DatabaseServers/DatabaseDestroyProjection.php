<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDestroyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $input = $operation->input;
        if (! $operation->status->terminal()) {
            $server = DatabaseServer::query()->find($operation->target_id);
            if ($server === null || $server->node_id !== $operation->node_id || $server->driver->value !== ($input['driver'] ?? null)
                || $server->port !== ($input['port'] ?? null) || $server->node->wireguard_ip !== ($input['host'] ?? null)
                || ($server->process === null ? null : app(DockerProcessRenderer::class)->containerName($server->process)) !== ($input['container'] ?? null)) {
                $this->invalid();
            }
            $records = $input['connections'] ?? null;
            if (! is_array($records)) {
                $this->invalid();
            }
            foreach (ValidatedData::integerList($input['connection_ids']) as $id) {
                $connection = DatabaseConnection::query()->find($id);
                if ($connection === null || $connection->database_server_id !== $server->id
                    || $connection->only(['database', 'test_database', 'test_processes', 'username', 'owner_instance_id']) !== ($records[$id] ?? null)
                    || ($connection->targets()->exists() && (($input['owner_instance_id'] ?? null) === null || $connection->owner_instance_id !== $input['owner_instance_id']))) {
                    $this->invalid();
                }
                foreach (ValidatedData::stringList($input['owned_prefixes']) as $prefix) {
                    if ($connection->test_database === $prefix && ! app(DatabaseOwnership::class)->ownsMysqlPrefix($connection, $prefix)) {
                        $this->invalid();
                    }
                }
            }
            app(DropServerDatabaseAction::class)->assertUsersStillUnshared($operation, $intent);
        }

        return array_intersect_key($input, array_flip(['driver', 'container', 'host', 'port', 'default_open', 'databases', 'users', 'owned_prefixes', 'agent_sha256']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && ($result !== ['backend' => 'absent'] || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($result !== ['error_code' => $code] || ! in_array($code, ['agent.version_mismatch', 'database.in_use', 'database.ownership_unproven', 'database.server_admin_invalid', 'database.server_admin_failed', 'database.server_command_failed', 'database.server_not_ready'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'Removal needs its bounded native result.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->input($operation);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
            if (! $terminal && $status === 'succeeded') {
                foreach ($this->intent($operation)->payload['projection']['delete_connections'] ?? [] as $id) {
                    $connection = DatabaseConnection::query()->findOrFail($id);
                    $connection->delete();
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseDeleted, $id, ['id' => $id, 'slug' => $connection->slug]);
                }
            }

            return $projected;
        });
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($id === null || $operation->kind !== 'database.destroy' || $operation->target_type !== 'database_server') {
            $this->invalid();
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $input = $operation->input;
        foreach ($intent->payload['admission']['input'] ?? [] as $key => $value) {
            if (! array_key_exists($key, $input) || $input[$key] !== $value) {
                $this->invalid();
            }
        }
        if (count($intent->payload['commands']) !== 1 || ($intent->payload['commands']['database-apply']['name'] ?? null) !== 'database.destroy'
            || ($intent->payload['commands']['database-apply']['protected'] ?? []) !== ['password', 'sql', 'restore_sql', 'inventory_sql', 'payload']
            || ($intent->payload['projection']['delete_connections'] ?? null) !== ($input['connection_ids'] ?? null)
            || ($input['connection_ids'] ?? []) === [] || ($intent->payload['projection']['children'] ?? []) !== []
            || ! is_string($input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input['agent_sha256']) !== 1) {
            $this->invalid();
        }

        return $intent;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.destroy_intent_invalid', 'Removal does not match its admitted Database ownership.', 422);
    }
}
