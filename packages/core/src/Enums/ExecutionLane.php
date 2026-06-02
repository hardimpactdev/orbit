<?php

declare(strict_types=1);

namespace Orbit\Core\Enums;

enum ExecutionLane: string
{
    case Host = 'host';
    case OrbitGateway = 'orbit-gateway';
    case LocalExecutor = 'local-executor';
}
