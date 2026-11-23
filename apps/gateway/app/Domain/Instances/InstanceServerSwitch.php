<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Domain\Instances\Power\InstancePower;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Switches an existing container runtime from `t3` to `conn` (docs/reference/development-containers.md#container-server).
 * A running container switches at once after confirming no agent turn or service command is active; a stopped container records
 * `conn` immediately and writes its drop-in before its server starts at the next wake. `conn` to `t3` and serverless runtimes
 * are refused.
 */
final readonly class InstanceServerSwitch implements DirectOperationProjection
{
    public const string Kind = 'instance.server_switch';

    /**
     * How long a switch, or the wake that applies a deferred one, waits for the Conn Server: it listens only once its first-start
     * import of T3's threads has finished, which takes minutes on a large home. The run budgets of both kinds cover it.
     */
    public const int ImportSeconds = 600;

    /** @param iterable<InstanceAgentActivity> $agents */
    public function __construct(#[Tag(InstanceAgentActivity::class)] private iterable $agents) {}

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(self::Kind)) !== null) {
            return $replay;
        }
        $current = Instance::query()->with(['node', 'project'])->findOrFail($instance->id);
        $currentRuntime = $current->runtime()->first();
        $this->assertSwitchable($current, $currentRuntime);
        if ($currentRuntime !== null && $currentRuntime->power_state === InstanceRuntimePowerState::Running) {
            $this->assertAgentIdle($current);
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->lockForUpdate()->first();
            $this->assertSwitchable($instance, $runtime);
            assert($runtime instanceof InstanceRuntime);
            $folders = app(InstanceContainer::class)->folders();

            if ($runtime->power_state === InstanceRuntimePowerState::Stopped) {
                $operation = app(StartOperationAction::class)->execute(
                    self::Kind,
                    'instance',
                    $instance->id,
                    $instance->node_id,
                    ['instance_id' => $instance->id, 'container_server' => ContainerServer::Conn->value, 'switch' => 'deferred'],
                    $caller,
                    $requestId,
                    lockKey: OperationStore::lockKey('instance', $instance->id),
                    start: false,
                );
                $runtime->update(['container_server' => ContainerServer::Conn]);
                $now = Carbon::now();
                app(OperationStore::class)->change($operation, [
                    'status' => OperationStatus::Succeeded,
                    'result' => ['container_server' => ContainerServer::Conn->value, 'switch' => 'deferred'],
                    'started_at' => $now,
                    'finished_at' => $now,
                    'lock_key' => null,
                ]);

                return $operation->refresh();
            }

            $intent = [
                'instance_id' => $instance->id,
                't3_port' => InstanceHostIntent::T3Port,
                'program' => ContainerServer::Conn->program(),
                'version_pattern' => ContainerServer::Conn->versionPattern(),
                'import_seconds' => self::ImportSeconds,
                'drop_in' => [
                    'path' => InstanceContainer::ServerDropIn,
                    'mode' => '0644',
                    'content' => ContainerServer::Conn->dropIn($folders),
                ],
                'container' => [
                    'project' => $runtime->incus_project,
                    'name' => $runtime->container_name,
                ],
            ];

            return app(StartOperationAction::class)->execute(
                self::Kind,
                'instance',
                $instance->id,
                $instance->node_id,
                $intent,
                $caller,
                $requestId,
                lockKey: OperationStore::lockKey('instance', $instance->id),
            );
        });
    }

    /**
     * Whether a deferred server switch occurred after the Instance's last wake or start, so a frozen container must take the
     * workflow wake path instead of a fast resume to apply its drop-in and restart the server.
     */
    public static function pendingForWake(Instance $instance): bool
    {
        $lastSwitch = Operation::query()
            ->where('kind', self::Kind)
            ->where('target_type', 'instance')
            ->where('target_id', $instance->id)
            ->where('status', OperationStatus::Succeeded)
            ->orderByDesc('id')
            ->first();
        if (! $lastSwitch instanceof Operation || ($lastSwitch->result['switch'] ?? null) !== 'deferred') {
            return false;
        }
        $lastWakeId = Operation::query()
            ->whereIn('kind', [InstanceWake::Kind, InstanceStart::Kind])
            ->where('target_type', 'instance')
            ->where('target_id', $instance->id)
            ->where('status', OperationStatus::Succeeded)
            ->max('id');

        return ! is_numeric($lastWakeId) || $lastSwitch->id > (int) $lastWakeId;
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);

        return [
            'intent' => [
                ...$operation->input,
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            ],
            'execution_secrets' => [],
        ];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $status = ValidatedData::string($data['status']);
            $raw = is_array($data['result'] ?? null) ? $data['result'] : [];
            $result = [];
            $serverVersion = null;
            if ($status === OperationStatus::Succeeded->value) {
                $switch = $raw['switch'] ?? null;
                if (! in_array($switch, ['switched', 'deferred'], true)) {
                    throw new ResourceOperationException('operation.result_invalid', 'The server switch report names no valid outcome.', 422);
                }
                $serverVersion = is_string($raw['server_version'] ?? null) && $raw['server_version'] !== '' ? $raw['server_version'] : null;
                if ($switch === 'switched' && $serverVersion === null) {
                    throw new ResourceOperationException('operation.result_invalid', 'The server switch report names no server version.', 422);
                }
                $result = [
                    'container_server' => ContainerServer::Conn->value,
                    'switch' => $switch,
                    ...($serverVersion === null ? [] : ['server_version' => $serverVersion]),
                ];
            } elseif (in_array($status, [OperationStatus::Failed->value, OperationStatus::Interrupted->value], true)) {
                $code = $raw['error_code'] ?? $data['error_code'] ?? null;
                $code = is_string($code) && preg_match('/\A(?:instance|conn)\.[a-z0-9_]{1,80}\z/D', $code) === 1
                    ? $code
                    : 'instance.container_server_switch_failed';
                $data['error_code'] = $code;
                // A run that failed after it wrote the Conn Server drop-in left the unit on Conn: the record follows the unit.
                $written = ($raw['container_server'] ?? null) === ContainerServer::Conn->value;
                $result = ['error_code' => $code, ...($written ? ['container_server' => ContainerServer::Conn->value] : [])];
            }

            $operation = app(DirectOperation::class)->project($operation, $data, $result);
            if ($operation->status === OperationStatus::Succeeded || ($operation->result['container_server'] ?? null) === ContainerServer::Conn->value) {
                InstanceRuntime::query()
                    ->where('instance_id', $operation->target_id)
                    ->where('container_server', ContainerServer::T3->value)
                    ->update(['container_server' => ContainerServer::Conn]);
                if ($serverVersion !== null) {
                    $instance = Instance::query()->find($operation->target_id);
                    if ($instance !== null) {
                        app(InstanceLifecycleContributions::class)->projectServerVersion($instance, $serverVersion);
                    }
                }
            }

            return $operation;
        });
    }

    private function assertSwitchable(Instance $instance, ?InstanceRuntime $runtime): void
    {
        if ($runtime === null || $runtime->container_server === null) {
            throw new ResourceOperationException('instance.container_server_none', 'This Instance has no container server to switch.', 409);
        }
        if ($runtime->container_server === ContainerServer::Conn) {
            throw new ResourceOperationException('instance.container_server_already_conn', 'This Instance already runs the Conn Server.', 409);
        }
        ContainerServer::Conn->assertPinned(app(InstanceContainer::class)->folders());
        InstancePower::assertIdle($instance);
        if (InstancePower::hasActiveTask($instance)) {
            throw new ResourceOperationException('instance.container_server_busy', 'The Instance has an active task and cannot switch its server now.', 409);
        }
    }

    private function assertAgentIdle(Instance $instance): void
    {
        foreach ($this->agents as $agent) {
            $activity = $agent->read($instance);
            if ($activity === null) {
                continue;
            }
            if (! $activity->readable) {
                throw new ResourceOperationException('instance.t3_unreadable', 'The container T3 server could not be read to confirm it is idle.', 409);
            }
            if ($activity->turnRuns || $activity->pendingRequest || $activity->held !== null) {
                throw new ResourceOperationException('instance.container_server_busy', 'The container has active agent work and cannot switch its server now.', 409);
            }
        }
    }
}
