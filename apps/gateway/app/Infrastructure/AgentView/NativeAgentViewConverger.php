<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\AgentView\AgentViewConverger;
use App\Infrastructure\Gateway\GatewayServiceCommands;

final readonly class NativeAgentViewConverger implements AgentViewConverger
{
    public function __construct(private GatewayServiceCommands $commands) {}

    public function converge(): void
    {
        $this->commands->agentView();
    }
}
