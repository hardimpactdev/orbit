<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\WebSocketAppTarget;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * `env:sync` on a container Instance. Orbit never renders an existing container's `.env` again, so this writes only the keys of the
 * Instance's attached connections into the file in place, adds the network paths to their servers, and restarts what runs.
 * The values travel only as one vault copy; the Operation input and result hold key names.
 */
final readonly class ContainerConnectionEnvironment implements DirectOperationProjection
{
    public const string Kind = 'instance.connection_environment';

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(self::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->first();
            if ($runtime === null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'This Operation needs a container Instance.', 409);
            }
            if ($instance->status !== InstanceState::Active
                || Operation::query()->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance has unfinished lifecycle work.', 409);
            }
            if ($runtime->power_state !== InstanceRuntimePowerState::Running) {
                throw new ResourceOperationException('process.target_inactive', 'The container is stopped; env:sync does not start it.', 409);
            }
            // With no key to write, the run still reconciles the websocket path: a removed app closes it in the running container.
            $keys = array_keys($this->lines($instance));
            $paths = app(InstanceContainer::class)->connectionPaths($instance, $runtime);
            $container = app(InstanceContainer::class)->intent($instance, $runtime);
            $running = $container['web_config'] === null ? [] : [InstanceContainer::FrankenPhpUnit];
            foreach (app(ContainerProcesses::class)->units($instance) as $unit) {
                if ($unit['desired'] === 'running') {
                    $running[] = $unit['name'];
                }
            }

            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id, [
                'container' => ['instance_id' => $instance->id, 'project' => $container['project'], 'name' => $container['name'],
                    'acl' => $container['acl'], 'network' => $container['network'], 'bridge_cidr' => $container['bridge_cidr'],
                    'bridge_admitted_at' => $container['bridge_admitted_at'], 'environment_file' => $container['environment_file']],
                'connection_environment' => ['keys' => $keys, ...$paths,
                    // The one composed NO_PROXY value when it is not the base, for the one guest writer; null leaves the container's files alone.
                    'proxy_environment' => $container['proxy_environment'] ?? null,
                    // What runs in the Instance: its own web unit and its running Process units. try-restart skips a stopped unit.
                    'refresh' => ['php' => $container['php'], 'application' => InstanceContainer::application($instance), 'units' => $running]],
            ], $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    /**
     * The `.env` line of each key of each attached connection, rendered from the connection records now, as the create renders them.
     *
     * @return array<string, string>
     */
    public function lines(Instance $instance): array
    {
        $projection = app(DatabaseConnectionEnvProjection::class);
        $lines = [];
        foreach (DatabaseConnectionTarget::query()->with('databaseConnection')->where('instance_id', $instance->id)->orderBy('id')->get() as $target) {
            $projected = $projection->project($target->databaseConnection, $target->prefix, $target->network, $instance->node);
            foreach ($projected['values'] as $key => $value) {
                $lines[$key] = $key.'='.InstanceEnvironmentRenderer::quote($value);
            }
        }
        // The Instance's websocket app: its keys, as the attach stores them (docs/reference/websocket-apps.md#reach-from-a-container).
        $websocket = WebSocketAppTarget::query()->with(['webSocketApp', 'route'])->where('instance_id', $instance->id)->first();
        if ($websocket !== null) {
            foreach (app(WebSocketAppEnvProjection::class)->project($websocket->webSocketApp, $websocket) as $key => $value) {
                $lines[$key] = $key.'='.InstanceEnvironmentRenderer::quote($value);
            }
        }
        ksort($lines, SORT_STRING);

        return $lines;
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        $admitted = ValidatedData::stringList(ValidatedData::object($operation->input['connection_environment'])['keys']);
        $lines = $this->lines($instance);
        if (array_keys($lines) !== $admitted) {
            throw new ResourceOperationException('env.connection_changed', 'The attached connections changed after admission; run env:sync again.', 409);
        }
        $secrets = ['connection_environment' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-environment-'.$operation->id]];
        if (! $operation->status->terminal()) {
            $secrets['connection_environment'] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-environment-'.$operation->id,
                json_encode(['lines' => $lines], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return ['intent' => [...$operation->input, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
            'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $wasTerminal = $operation->refresh()->status->terminal();
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            $environment = is_array($result['environment'] ?? null) ? $result['environment'] : [];
            $projected = app(DirectOperation::class)->project($operation,
                [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null], $result);
            if (! $wasTerminal && $projected->status === OperationStatus::Succeeded) {
                // The keys Orbit now holds in the file; a `${...}` value the Project wrote stays the Project's.
                $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
                $keys = [...($instance->environment_owned_keys ?? []), ...ValidatedData::stringList($environment['written'] ?? []),
                    ...ValidatedData::stringList($environment['added'] ?? []), ...ValidatedData::stringList($environment['unchanged'] ?? [])];
                $keys = array_values(array_unique($keys));
                sort($keys, SORT_STRING);
                $instance->forceFill(['environment_owned_keys' => $keys])->saveOrFail();
            }

            return $projected;
        });
    }
}
