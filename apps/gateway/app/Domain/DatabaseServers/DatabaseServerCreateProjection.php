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
    private const array CODES = ['Pull the image' => 'database.server_image_pull_failed', 'Write the ACL file' => 'database.valkey_acl_failed',
        'Start the container' => 'database.server_start_failed', 'Wait until healthy' => 'database.server_unhealthy',
        'Check the admin login' => 'database.server_login_failed', 'Remove the first-start env' => 'database.server_scrub_failed'];

    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($this->intent($operation)->payload['admission']['input'] ?? null);
        $server = DatabaseServer::query()->findOrFail($operation->target_id);
        $initialize = $input['initialize'] === true;
        $secrets = ['login' => ['vault' => 'orbit-control-inputs', 'key' => "database-server-create-login-{$operation->id}"],
            'start' => ['vault' => 'orbit-control-inputs', 'key' => "database-server-create-start-{$operation->id}"]];
        if ($server->driver === DatabaseDriver::Redis) {
            $secrets['acl'] = ['vault' => 'orbit-control-inputs', 'key' => "database-server-create-intent-{$operation->id}"];
        }
        if (! $operation->status->terminal()) {
            $copy = app(SwampCommandStep::class);
            // The admin password is the first stdin line of the login check, followed by its statement (Q-DBAUTH).
            $login = $server->driver === DatabaseDriver::Redis ? "PING\n" : "SELECT 1;\n";
            $secrets['login'] = $copy->executionCopy('orbit-control-inputs', $secrets['login']['key'], "{$server->root_password}\n{$login}");
            // `docker run --env-file /dev/stdin` reads the first-start variables; a later start reads a blank line.
            $start = $initialize ? DatabaseServerProcess::firstStartEnvFile($server, $server->root_password) : "\n";
            $secrets['start'] = $copy->executionCopy('orbit-control-inputs', $secrets['start']['key'], $start);
            if (isset($secrets['acl'])) {
                $secrets['acl'] = $copy->executionCopy($secrets['acl']['vault'], $secrets['acl']['key'], ValkeyAcl::file($server));
            }
        }
        $commands = app(DatabaseServerCommands::class)->render(['driver' => ValidatedData::string($input['driver']),
            'process_id' => ValidatedData::integer($input['process_id']), 'container' => ValidatedData::string($input['container']),
            'container_input' => ValidatedData::object($input['container_input']), 'spec' => ValidatedData::string($input['spec']),
            'scrub_spec' => ValidatedData::string($input['scrub_spec']), 'initialize' => $initialize,
            'volume' => ValidatedData::nullableString($input['volume'] ?? null)]);

        return ['intent' => [...array_intersect_key($input, array_flip(['server_id', 'driver', 'container', 'initialize'])),
            'container_input' => ['image' => ValidatedData::object($input['container_input'])['image']], 'commands' => $commands,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        // Native result (no report step): the failed step names the error code, and the admin login must answer.
        $native = ValidatedData::object($data['result'] ?? []);
        $status = ValidatedData::string($data['status']);
        $failedStep = ValidatedData::stringList(ValidatedData::object($native['summary'] ?? [])['failed_steps'] ?? [])[0] ?? null;
        if ($status === 'succeeded') {
            $login = ValidatedData::object(ValidatedData::object($native['steps'] ?? [])['Check the admin login'] ?? []);
            $answer = trim(ValidatedData::nullableString($login['stdout'] ?? null) ?? '');
            if ($answer !== ($operation->input['driver'] === 'redis' ? 'PONG' : '1')) {
                [$status, $failedStep] = ['failed', 'Check the admin login'];
                $data = [...$data, 'status' => 'failed', 'failure' => ['failed_step' => $failedStep, 'stderr_recorded' => true]];
            }
        }
        $code = $status === 'succeeded' ? null : (self::CODES[$failedStep ?? ''] ?? ValidatedData::nullableString($data['error_code'] ?? null) ?? 'database.server_create_failed');
        $result = $status === 'succeeded' ? ['backend' => 'active'] : ['error_code' => $code];
        $data = [...$data, 'error_code' => $code];

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
