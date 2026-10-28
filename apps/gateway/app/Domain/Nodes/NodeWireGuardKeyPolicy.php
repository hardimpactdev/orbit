<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class NodeWireGuardKeyPolicy
{
    public function assertMutable(Node $node): void
    {
        if ($node->roles()->where('role', RoleName::Gateway)->exists()) {
            throw new ResourceOperationException('node.wireguard_key_gateway', 'Gateway WireGuard keys cannot be changed by this command.', 409);
        }
        if (is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            && is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '') {
            throw new ResourceOperationException('node.wireguard_key_managed', 'Managed Node WireGuard keys cannot be changed by this command.', 409);
        }
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException('node.wireguard_ip_required', 'The Node needs an existing WireGuard address.');
        }
    }
}
