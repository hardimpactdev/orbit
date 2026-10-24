<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Infrastructure\Gateway\GatewayServiceCommands;

final readonly class NativeRuntimeHibernatorConverger implements RuntimeHibernatorConverger
{
    public function __construct(
        private GatewayServiceCommands $commands,
        private int $sweepSeconds = RuntimeHibernation::DefaultSweepSeconds,
    ) {}

    public function converge(): void
    {
        $this->commands->hibernator($this->sweepSeconds);
    }
}
