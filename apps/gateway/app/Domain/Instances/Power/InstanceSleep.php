<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;

/** `instance:sleep`: the command reads no T3 state, so its result says a turn was not checked (docs/cli/instance/sleep.mdx). */
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

    protected function result(): array
    {
        return [...parent::result(), 't3_turn' => 'not_checked'];
    }
}
