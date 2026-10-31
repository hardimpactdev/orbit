<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
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
    private function admittedInput(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $input = ValidatedData::object($intent->payload['admission']['input'] ?? null);
        $container = ValidatedData::object($input['container_input']);
        if (array_diff(array_keys($container), ['image', 'command', 'restart', 'working_directory', 'ports', 'mounts']) !== []) {
            $this->invalid();
        }

        return [...array_intersect_key($input, array_flip(['driver', 'process_id', 'container', 'spec', 'scrub_spec', 'initialize', 'validate_existing', 'was_active', 'default_open', 'host', 'port', 'volume'])),
            'container_input' => $container];
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
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            $server = DatabaseServer::query()->find($operation->target_id);
            if (! $terminal && $server !== null && $status === 'succeeded') {
                $projection = $this->intent($operation)->payload['projection'];
                $server->update($projection['server'] ?? []);
                $server->process?->fill($projection['process'] ?? [])->fill(['status' => 'active', 'failed_step' => null, 'error_code' => null])->save();
                if (($id = $this->intent($operation)->payload['admission']['input']['adoption_connection_id'] ?? null) !== null) {
                    DatabaseConnection::query()->find(ValidatedData::integer($id))?->update(['database_server_id' => $server->id]);
                }
            } elseif (! $terminal && $server !== null && in_array($status, ['failed', 'cancelled'], true)) {
                $admitted = ValidatedData::object($this->intent($operation)->payload['admission']['input'] ?? null);
                if ($status === 'failed' && $code === 'database.server_adoption_refused'
                    && $admitted['created'] && $admitted['validate_existing'] && ! $admitted['was_active']
                    && $admitted['adoption_connection_id'] !== null && $server->status->value !== 'active') {
                    $server->delete();

                    return $projected;
                }
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

        return $intent;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_intent_invalid', 'Server creation does not match its admitted records.', 422);
    }
}
