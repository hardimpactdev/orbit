<?php

declare(strict_types=1);

namespace Hardimpactdev\OrbitCore\Enums;

enum ExecutionLane: string
{
    case Host = 'host';
    case OrbitRuntime = 'orbit-runtime';
    case LocalExecutor = 'local-executor';
}
