<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet;

use App\Domain\Fleet\FleetConvergeUnits;
use App\Infrastructure\Gateway\GatewayServiceCommands;

final readonly class NativeFleetConvergeUnits implements FleetConvergeUnits
{
    public function __construct(private GatewayServiceCommands $commands) {}

    public function converge(): void
    {
        $this->commands->fleet();
    }

    public function start(): bool
    {
        return $this->commands->startFleet(false);
    }

    public function startLater(): bool
    {
        return $this->commands->startFleet(true);
    }
}
