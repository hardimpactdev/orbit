<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Models\Instance;

/**
 * The agent activity in a running development container, for the idle sleep (docs/reference/development-containers.md#sleep-and-wake).
 * The Conn layer contributes the read of the container's T3 server with the Gateway's admin session. The idle sleep calls it once per
 * candidate per sweep, never for a sleeping Instance. Without a contribution, as while the Conn extension is off, every Instance reads as
 * unreadable, and the idle sleep puts none to sleep.
 */
interface InstanceAgentActivity
{
    /** Null when this contribution has no agent server for the Instance. */
    public function read(Instance $instance): ?AgentActivity;
}
