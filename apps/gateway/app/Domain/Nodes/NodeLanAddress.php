<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\LanAddress;
use App\Models\Node;

/**
 * A LAN address names one machine on one private network (ADR 0251). The hub's private network holds Nodes inside and outside
 * Clusters, such as app-prod in Cluster prod beside services in none, so an address there is unique across every Node. Elsewhere
 * a Cluster is one network, and all Nodes outside every Cluster form one too. Two Nodes of one network never share an address:
 * the hub endpoint, Routes and Router rules would reach the wrong machine.
 */
final class NodeLanAddress
{
    public static function assertFree(string $address, ?int $clusterId, ?int $exceptNodeId): void
    {
        $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->first();
        $onHubNetwork = $hub instanceof Node && LanAddress::isPrivate($hub->lan_ip) && LanAddress::sameNetwork($hub->lan_ip, $address);
        $taken = Node::query()->where('lan_ip', $address)
            ->when(! $onHubNetwork, static fn ($query) => $clusterId === null ? $query->whereNull('cluster_id') : $query->where('cluster_id', $clusterId))
            ->when($exceptNodeId !== null, static fn ($query) => $query->whereKeyNot($exceptNodeId))
            ->exists();
        if ($taken) {
            throw new ResourceOperationException('cluster.lan_ip_conflict', "LAN IP [{$address}] is already assigned to another Node of the same network.", 409);
        }
    }
}
