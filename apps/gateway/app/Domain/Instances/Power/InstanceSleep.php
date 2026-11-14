<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;

/**
 * `instance:sleep`: the command reads no T3 state, so its result says a turn was not checked (docs/cli/instance/sleep.mdx). Its result
 * names how the container sleeps: `frozen`, or `stopped` when swap did not take its memory.
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

    /** The extensions read the server's threads one last time first, outside the Instance lock: a sleeping server cannot be read. */
    #[\Override]
    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        app(InstanceThreadContributions::class)->beforeSleep($instance);

        return parent::start($instance, $caller, $requestId);
    }

    protected function refuse(Instance $instance, InstanceRuntime $runtime): void
    {
        // A default's seed and deployments are a later part of the plan; it stays awake until then.
        if ($instance->name === 'default') {
            throw new ResourceOperationException('instance.sleep_unsupported', 'A default Instance does not sleep.', 409);
        }
        if (self::hasActiveTask($instance)) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'A task runs in the Instance.', 409);
        }
    }

    protected function result(Operation $operation): array
    {
        // The idle sweep read T3 and found no turn and no recent activity before it admitted the sleep.
        return [...parent::result($operation), 't3_turn' => $operation->triggered_by === ContainerIdleSweep::TriggeredBy ? 'idle' : 'not_checked'];
    }

    protected function phaseKeys(): array
    {
        return ['sleep'];
    }

    /** The anonymous memory that may stay in RAM after the reclaim; more means swap is full, and the sleep stops the container. */
    protected function intent(): array
    {
        return ['freeze' => ['resident_anon_mib' => 32]];
    }
}
