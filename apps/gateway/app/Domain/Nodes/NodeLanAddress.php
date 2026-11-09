<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

/**
 * A LAN address names one machine on one private network. A Cluster is one network; the Nodes outside every Cluster, the hub
 * and the data-centre Nodes among them, share the hub's network, so they form one group too (ADR 0251). Two Nodes of one group
 * never share an address: the hub endpoint, Routes and Router rules would reach the wrong machine.
 */
final class NodeLanAddress
{
    public static function assertFree(string $address, ?int $clusterId, ?int $exceptNodeId): void
    {
        $taken = Node::query()->where('lan_ip', $address)
            ->when($clusterId === null, static fn ($query) => $query->whereNull('cluster_id'), static fn ($query) => $query->where('cluster_id', $clusterId))
            ->when($exceptNodeId !== null, static fn ($query) => $query->whereKeyNot($exceptNodeId))
            ->exists();
        if ($taken) {
            throw new ResourceOperationException('cluster.lan_ip_conflict', "LAN IP [{$address}] is already assigned to another Node of the same network.", 409);
        }
    }
}
