<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\AppDev\ContributedPrivateDnsRecords;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliState;
use App\Models\Node;

/** The collector hostname on the collector Node's WireGuard address, while setup holds the collector. */
final readonly class ProxyCliPrivateDnsRecords implements ContributedPrivateDnsRecords
{
    public function __construct(private ProxyCliState $state) {}

    public function records(): array
    {
        if (! $this->state->enabled()) {
            return [];
        }

        $nodeId = $this->state->nodeId();

        if ($nodeId === null) {
            return [];
        }

        $node = Node::query()->find($nodeId);

        return $node instanceof Node && is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            ? [ProxyCliHostname::Value => $node->wireguard_ip]
            : [];
    }
}
