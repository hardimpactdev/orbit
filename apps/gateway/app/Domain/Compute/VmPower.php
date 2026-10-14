<?php

declare(strict_types=1);

namespace App\Domain\Compute;

enum VmPower: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Destroyed = 'destroyed';
}
