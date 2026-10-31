<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
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
        $intent = $this->intent($operation);
        $input = $this->admittedInput($operation);
        $node = Node::query()->findOrFail($operation->node_id);

        $copy = ['vault' => 'orbit-control-inputs', 'key' => 'database-server-create-intent-'.$intent->id];
        if (! $operation->status->terminal()) {
            $copy = app(SwampCommandStep::class)->executionCopy($copy['vault'], $copy['key'],
                json_encode($intent->payload['commands']['database-apply']['input'], JSON_THROW_ON_ERROR));
        }

        return ['intent' => [...$input, 'ssh' => ['host' => $node->wireguard_ip, 'user' => $node->user,
            'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key)
                ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []]],
            'execution_secrets' => ['server_create' => $copy]];
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
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = ($raw['backend'] ?? null) === 'active' ? ['backend' => 'active'] : [];
        if (is_string($raw['error_code'] ?? null) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $raw['error_code']) === 1) {
            $result['error_code'] = $raw['error_code'];
        }
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);

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
