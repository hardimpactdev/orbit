<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/** Whether a container Instance's container runs. A development container stops when it sleeps. */
enum InstanceRuntimePowerState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
}
