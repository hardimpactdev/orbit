<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Node;

/** Removes the LAN firewall allowance owned by a Route. */
final readonly class RemoteAppDevRouteFirewallManager
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function remove(Node $node, int $routeId): void
    {
        AgentStep::run($this->agents, $node, 'firewall.route-lan', [
            'operation' => 'remove',
            'route_id' => $routeId,
        ], 'route-firewall-remove', 'app-dev.route_firewall_failed', 'App development');
    }
}
