<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

use App\Infrastructure\AgentView\NativeAgentViewConverger;
use Illuminate\Container\Attributes\Bind;

/** Installs, enables, and restarts the agent view subscriber on the Gateway host. */
#[Bind(NativeAgentViewConverger::class)]
interface AgentViewConverger
{
    public function converge(): void;
}
