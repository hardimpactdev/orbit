<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Routes\ContributedPrivateHostnames;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/** Reserves the collector name even while AiRouter is off; publishes its Node's WireGuard address while set up. */
final readonly class AiRouterPrivateHostnames implements ContributedPrivateHostnames
{
    public function __construct(private AiRouterState $state) {}

    public function hostnames(): array
    {
        $node = $this->state->enabled() ? Node::query()->find($this->state->nodeId()) : null;
        $address = $node instanceof Node && is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            ? $node->wireguard_ip
            : null;

        return [AiRouterHostname::Value => $address];
    }
}
