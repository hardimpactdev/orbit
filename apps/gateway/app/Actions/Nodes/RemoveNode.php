<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\WireGuardPeer;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\WgEasyStateInstallerFailed;
use Illuminate\Support\Facades\DB;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeRemoveResponse;
use Throwable;

final readonly class RemoveNode
{
    public function __construct(
        private DnsmasqReconciler $dnsmasqReconciler,
        private VpnDnsSwarmInstaller $vpnDnsSwarmInstaller,
    ) {}

    public function handle(Node $node, bool $removedSelf): NodeRemoveResponse
    {
        $name = $node->name;
        $wireguardPeer = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->first();

        if ($removedSelf && $wireguardPeer instanceof WireGuardPeer) {
            throw NodeRemovalFailed::selfRequiresRemoteCaller($name);
        }

        $wireguardPeerRemoved = false;

        if ($wireguardPeer instanceof WireGuardPeer) {
            try {
                $this->vpnDnsSwarmInstaller->removePeer($wireguardPeer->public_key);
            } catch (Throwable $exception) {
                $meta = [
                    'name' => $name,
                    'retryable' => true,
                ];

                if ($exception instanceof WgEasyStateInstallerFailed) {
                    $meta = [...$meta, ...$exception->meta];
                }

                throw NodeRemovalFailed::wireGuardPeerRemoval($name, $meta, $exception);
            }

            $wireguardPeerRemoved = true;
        }

        $grantsRemoved = DB::transaction(function () use ($name, $node, $wireguardPeer, $wireguardPeerRemoved): int {
            if ($wireguardPeer instanceof WireGuardPeer) {
                $wireguardPeer->delete();
            }

            $grantsRemoved = (int) NodeAccess::query()
                ->where('consumer_node_id', $node->id)
                ->orWhere('serving_node_id', $node->id)
                ->delete();

            FirewallRule::query()
                ->where('node_id', $node->id)
                ->delete();

            $node->delete();

            try {
                $this->dnsmasqReconciler->reconcileRecords();
            } catch (Throwable $exception) {
                throw NodeRemovalFailed::dnsReconciliation($name, $wireguardPeerRemoved, $exception);
            }

            return $grantsRemoved;
        });

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
