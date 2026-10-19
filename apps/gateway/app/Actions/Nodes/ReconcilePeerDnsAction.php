<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ReconcilePeerDnsAction
{
    public function __construct(private PeerDnsPolicy $policy, private PeerDnsInspector $inspector, private RepairNodeDnsAction $repair) {}

    /** @return array<string, string> Error codes keyed by failed Node name. */
    public function execute(): array
    {
        $this->policy->domains();
        $failed = [];
        foreach (Node::query()->with('roles')->orderBy('id')->get() as $node) {
            if (! $this->policy->manages($node)) {
                continue;
            }
            try {
                $state = $this->inspector->inspect($node);
                if (! $state['policy']) {
                    $this->repair->execute($node->name);
                } elseif (! $state['uplink']) {
                    throw new NodeProvisioningException('wireguard-peer-dns-uplink', 'vpn.peer_dns_uplink_missing', 'No usable non-Orbit DNS server is configured.');
                }
            } catch (Throwable $exception) {
                $code = $exception instanceof NodeProvisioningException ? $exception->errorCode : 'vpn.peer_dns_reconcile_failed';
                $failed[$node->name] = $code;
                Log::warning('Peer DNS reconciliation failed.', ['node_id' => $node->id, 'error_code' => $code, 'exception' => $exception::class]);
            }
        }

        return $failed;
    }
}
