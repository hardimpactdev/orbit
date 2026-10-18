<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Models\Node;

/** Reserves the collector name even while ProxyCli is off; publishes its Node's WireGuard address while set up. */
final readonly class ProxyCliPrivateHostnames implements ContributedPrivateHostnames
{
    public function __construct(private ProxyCliState $state) {}

    public function hostnames(): array
    {
        $node = $this->state->enabled() ? Node::query()->find($this->state->nodeId()) : null;
        $address = $node instanceof Node && is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            ? $node->wireguard_ip
            : null;

        return [ProxyCliHostname::Value => $address];
    }
}
