<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;

/** Records policy intent without contacting a Node. */
final readonly class AdmitPeerDnsAction
{
    public function __construct(private PeerDnsPolicy $policy, private StartOperationAction $start) {}

    public function execute(Node $node, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        $node->load('roles');
        if (! $this->policy->manages($node)) {
            throw new ResourceOperationException('vpn.peer_dns_unmanaged', 'The Node does not use the managed peer DNS policy.', 422);
        }
        $server = $this->policy->server($node);

        return $this->start->execute('fleet.peer_dns', 'peer_dns', $node->id, $node->id, [
            'server' => $server, 'dns_server' => $server, 'public_key' => $node->wireguard_public_key,
            'domains' => $this->policy->domains($node),
        ], $triggeredBy, $requestId);
    }
}
