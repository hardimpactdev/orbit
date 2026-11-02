<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The admitted intent as run inputs, and the server and Process records from the terminal result. */
final readonly class DatabaseServerCreateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($this->intent($operation)->payload['admission']['input'] ?? null);
        $server = DatabaseServer::query()->findOrFail($operation->target_id);
        $initialize = $input['initialize'] === true;
        $secrets = ['client_env' => ['vault' => 'orbit-control-inputs', 'key' => "database-server-{$server->id}-client-env"]];
        if ($initialize) {
            $secrets['first_env'] = ['vault' => 'orbit-control-inputs', 'key' => "database-server-{$server->id}-first-env"];
        }
        if ($server->driver === DatabaseDriver::Redis) {
            $secrets['acl'] = ['vault' => 'orbit-control-inputs', 'key' => "database-server-create-intent-{$operation->id}"];
        }
        if (! $operation->status->terminal()) {
            $secrets['client_env'] = DatabaseServerAdminSecret::clientEnvCopy($server);
            if ($initialize) {
                $secrets['first_env'] = DatabaseServerAdminSecret::firstEnvCopy($server);
            }
            if (isset($secrets['acl'])) {
                $secrets['acl'] = app(SwampCommandStep::class)->executionCopy($secrets['acl']['vault'], $secrets['acl']['key'], ValkeyAcl::file($server));
            }
        }
        $commands = app(DatabaseServerCommands::class)->render(['driver' => ValidatedData::string($input['driver']),
            'process_id' => ValidatedData::integer($input['process_id']), 'container' => ValidatedData::string($input['container']),
            'container_input' => ValidatedData::object($input['container_input']), 'spec' => ValidatedData::string($input['spec']),
            'scrub_spec' => ValidatedData::string($input['scrub_spec']), 'initialize' => $initialize,
            'volume' => ValidatedData::nullableString($input['volume'] ?? null)], $operation->id);

        return ['intent' => [...array_intersect_key($input, array_flip(['server_id', 'driver', 'container', 'initialize'])),
            'container_input' => ['image' => ValidatedData::object($input['container_input'])['image']], 'commands' => $commands,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = ($raw['backend'] ?? null) === 'active' ? ['backend' => 'active'] : [];
        if (is_string($raw['error_code'] ?? null) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $raw['error_code']) === 1) {
            $result['error_code'] = $raw['error_code'];
        }
        $failedStep = is_string($raw['failed_step'] ?? null) ? $raw['failed_step'] : null;
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null) ?? ($result['error_code'] ?? null);

        return DB::transaction(function () use ($operation, $data, $result, $status, $code, $failedStep): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            $server = DatabaseServer::query()->find($operation->target_id);
            if (! $terminal && $server !== null && $status === 'succeeded') {
                $projection = $this->intent($operation)->payload['projection'];
                $server->update($projection['server'] ?? []);
                $server->process?->fill($projection['process'] ?? [])->fill(['status' => 'active', 'failed_step' => null, 'error_code' => null])->save();
            } elseif (! $terminal && $server !== null && in_array($status, ['failed', 'cancelled', 'interrupted'], true)) {
                $server->update(['status' => $server->status->value === 'active' ? 'active' : 'failed', 'error_code' => $code, 'failed_step' => $failedStep]);
            }

            return $projected;
        });
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($operation->kind !== 'database_server.create' || $operation->target_type !== 'database_server' || $id === null) {
            throw new ResourceOperationException('database.server_intent_invalid', 'Server creation does not match its admitted records.', 422);
        }

        return DatabaseExecutionIntent::recorded($id);
    }
}
