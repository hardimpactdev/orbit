<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;

/**
 * `instance:sleep`: the command reads no T3 state, so its result says a turn was not checked (docs/cli/instance/sleep.mdx), and an
 * Instance without a T3 server says it has none. Its result names how the container sleeps: `frozen`, or `stopped` when swap did not
 * take its memory; and `swap_subtree`, the number of inner cgroups the freeze opened, which only the wake's run closes again.
 */
final readonly class InstanceSleep extends InstancePower
{
    public const string Kind = 'instance.sleep';

    protected function kind(): string
    {
        return self::Kind;
    }

    protected function powerState(): InstanceRuntimePowerState
    {
        return InstanceRuntimePowerState::Stopped;
    }

    /** The extensions read the server's threads one last time, after the refusals and outside the Instance lock: a sleeping server cannot be read. */
    #[\Override]
    protected function beforeAdmission(Instance $instance): void
    {
        app(InstanceThreadContributions::class)->beforeSleep($instance);
    }

    protected function refuse(Instance $instance, InstanceRuntime $runtime): void
    {
        if (self::hasActiveTask($instance)) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'A task runs in the Instance.', 409);
        }
    }

    protected function result(Operation $operation): array
    {
        $server = InstanceRuntime::query()->where('instance_id', $operation->target_id)->value('t3_port') !== null;

        // The idle sweep read T3 and found no turn and no recent activity before it admitted the sleep.
        return [...parent::result($operation), 't3_turn' => match (true) {
            ! $server => 'no_server',
            $operation->triggered_by === ContainerIdleSweep::TriggeredBy => 'idle',
            default => 'not_checked',
        }];
    }

    protected function phaseKeys(): array
    {
        return ['sleep', 'swap_subtree'];
    }

    /** The Instance's newest sleep while its runtime records it stopped and that sleep succeeded: it sleeps behind its asleep marker. */
    public static function asleep(Instance $instance, InstanceRuntime $runtime): ?Operation
    {
        if ($runtime->power_state !== InstanceRuntimePowerState::Stopped) {
            return null;
        }
        $sleep = Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->orderByDesc('id')->first();

        return $sleep?->status === OperationStatus::Succeeded ? $sleep : null;
    }

    /**
     * The sleep that froze the Instance, while it still holds: no wake since found the container stopped, as after a host reboot. A
     * nested-fleet Instance wakes by resume only then; otherwise only its start or its deployment brings back its fleet.
     */
    public static function frozen(Instance $instance, InstanceRuntime $runtime): ?Operation
    {
        $sleep = self::asleep($instance, $runtime);
        if ($sleep === null || ($sleep->result['sleep'] ?? null) !== 'frozen') {
            return null;
        }
        $found = Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where('id', '>', $sleep->id)->where('error_code', InstanceWake::NestedStopped)->exists();

        return $found ? null : $sleep;
    }

    /** The anonymous memory that may stay in RAM after the reclaim; more means swap is full, and the sleep stops the container. */
    protected function intent(): array
    {
        return ['freeze' => ['resident_anon_mib' => 32]];
    }
}
