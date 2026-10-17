<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum TopologyNodePurpose: string
{
    case Gateway = 'gateway';
    case Operator = 'operator';
    case Workload = 'workload';

    /**
     * The Orbit roles of the test Gateway and of the operator, whose convergence programs `orbit:node-programs` renders.
     * A workload Node's roles come from the catalog.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return match ($this) {
            self::Gateway => ['gateway', 'vpn'],
            self::Operator, self::Workload => [],
        };
    }
}
