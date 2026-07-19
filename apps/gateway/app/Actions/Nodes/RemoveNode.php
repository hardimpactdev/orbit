<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\WireGuardPeer;
use App\Services\Dns\DnsmasqReconciler;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeRemoveResponse;

final readonly class RemoveNode
{
    public function __construct(
        private DnsmasqReconciler $dnsmasqReconciler,
    ) {}

    public function handle(Node $node, bool $removedSelf): NodeRemoveResponse
    {
        $name = $node->name;

        $grantsRemoved = NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->orWhere('serving_node_id', $node->id)
            ->delete();

        $wireguardPeerRemoved = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->delete() > 0;

        FirewallRule::query()
            ->where('node_id', $node->id)
            ->delete();

        $node->delete();

        $this->dnsmasqReconciler->reconcileRecords();

        return new NodeRemoveResponse(
            name: $name,
            removed: true,
            removedSelf: $removedSelf,
            wireguardPeerRemoved: $wireguardPeerRemoved,
            grantsRemoved: $grantsRemoved,
            warnings: [],
        );
    }
}
