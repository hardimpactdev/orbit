<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseServerCreateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return $this->admittedInput($operation);
    }

    /** @return array<string, mixed> */
    private function admittedInput(Operation $operation, bool $checkPolicy = true): array
    {
        $intent = $this->intent($operation);
        $input = ValidatedData::object($intent->payload['admission']['input'] ?? null);
        $container = ValidatedData::object($input['container_input']);
        if (array_diff(array_keys($container), ['image', 'command', 'restart', 'working_directory', 'ports', 'mounts']) !== []) {
            $this->invalid();
        }
        if (! $operation->status->terminal()) {
            $server = DatabaseServer::query()->find($operation->target_id);
            $process = $server?->process;
            if ($server === null || $server->status->value === 'removing' || $server->node_id !== $operation->node_id || $server->driver->value !== $input['driver']
                || ($checkPolicy && $server->default_open !== $input['default_open']) || $server->process_id !== $input['process_id'] || $server->port !== $input['port'] || $server->node->wireguard_ip !== $input['host']
                || $process === null || $process->runtime->value !== 'docker' || $process->owner_type !== Node::class || $process->owner_id !== $operation->node_id
                || app(DockerProcessRenderer::class)->containerName($process) !== $input['container']) {
                $this->invalid();
            }
            if ($input['adoption_connection_id'] !== null) {
                $connection = DatabaseConnection::query()->find(ValidatedData::integer($input['adoption_connection_id']));
                if ($connection === null || $connection->slug !== ($intent->payload['projection']['adopted_connection'] ?? null)
                    || ($connection->database_server_id !== null && $connection->database_server_id !== $server->id)
                    || $connection->host !== $input['host'] || ($connection->port ?? 6379) !== $input['port']) {
                    $this->invalid();
                }
            }
        }

        return [...array_intersect_key($input, array_flip(['driver', 'process_id', 'container', 'spec', 'scrub_spec', 'initialize', 'validate_existing', 'was_active', 'default_open', 'host', 'port', 'volume'])),
            'container_input' => $container, 'agent_sha256' => $operation->input['agent_sha256']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && ($result !== ['backend' => 'active'] || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($result !== ['error_code' => $code] || ! in_array($code, ['agent.version_mismatch', 'database.server_adoption_refused', 'database.server_policy_changed', 'database.server_command_failed', 'database.server_admin_invalid', 'database.server_admin_failed', 'database.server_not_ready', 'database.valkey_acl_invalid', 'database.valkey_acl_failed', 'process.invalid', 'process.name_collision', 'process.start_failed', 'process.stop_failed', 'process.restart_failed', 'process.converge_failed', 'process.recovery_required'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'Server creation needs its closed native result.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status, $code): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->admittedInput($operation, in_array($status, ['running', 'succeeded'], true));
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
            $server = DatabaseServer::query()->find($operation->target_id);
            if (! $terminal && $server !== null && $status === 'succeeded') {
                $projection = $this->intent($operation)->payload['projection'];
                $server->update($projection['server'] ?? []);
                $server->process?->fill($projection['process'] ?? [])->fill(['status' => 'active', 'failed_step' => null, 'error_code' => null])->save();
                if (($id = $this->intent($operation)->payload['admission']['input']['adoption_connection_id'] ?? null) !== null) {
                    DatabaseConnection::query()->whereKey($id)->update(['database_server_id' => $server->id]);
                }
            } elseif (! $terminal && $server !== null && in_array($status, ['failed', 'cancelled'], true)) {
                $server->update(['status' => $server->status->value === 'active' ? 'active' : 'failed', 'error_code' => $code, 'failed_step' => 'database-apply']);
            }

            return $projected;
        });
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($operation->kind !== 'database_server.create' || $operation->target_type !== 'database_server' || $id === null) {
            $this->invalid();
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        $command = $intent->payload['commands']['database-apply'] ?? [];
        if (count($intent->payload['commands']) !== 1 || ($command['name'] ?? null) !== 'database.server-create'
            || ($command['protected'] ?? []) !== ['password', 'environment', 'acl', 'payload']
            || ($intent->payload['projection']['children'] ?? []) !== [] || ! isset($intent->payload['admission']['input'])
            || ! is_string($operation->input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $operation->input['agent_sha256']) !== 1) {
            $this->invalid();
        }

        return $intent;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_intent_invalid', 'Server creation does not match its admitted records.', 422);
    }
}
