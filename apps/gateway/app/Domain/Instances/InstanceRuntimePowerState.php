<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/**
 * Whether a container Instance's container runs. `stopped` is also a sleeping development container: a sleep freezes it, or stops
 * it when swap cannot take its memory (docs/reference/development-containers.md#sleep-and-wake). Each reader treats both alike.
 */
enum InstanceRuntimePowerState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
}
