<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Security\PinnedHostKey;
use App\Models\Node;
use App\Services\Security\SshHostKeyPinner;

final readonly class OperatorNodeManager
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manage(Node $node, string $user, string $platform): array
    {
        if (! $node->isActive() || ! $node->isOperator()) {
            throw new OperatorNodeManagementException(
                'node.not_operator',
                'Only active roleless nodes can opt into gateway SSH management.',
            );
        }

        $wireguardAddress = $this->wireguardAddress($node);

        $node->forceFill([
            'user' => $user,
            'platform' => $platform,
        ])->save();

        $pinner = app(SshHostKeyPinner::class);
        $pinnedHostKey = $pinner->pin($wireguardAddress);

        if (! $pinnedHostKey instanceof PinnedHostKey) {
            throw new OperatorNodeManagementException(
                'node.host_key_pin_failed',
                'Gateway SSH host key pinning failed.',
            );
        }

        $pinner->persist($node, $pinnedHostKey);
        $node->refresh();

        $result = $this->remoteShell->run($node, 'true', ['throw' => false]);

        if (! $result->successful()) {
            throw new OperatorNodeManagementException('node.ssh_unreachable', 'Gateway SSH reachability check failed.');
        }

        return [
            'node' => $node->name,
            'user' => $node->user,
            'platform' => $node->platform,
            'ssh_host' => $wireguardAddress,
            'host_key_pinned' => true,
            'ssh_verified' => true,
        ];
    }

    private function wireguardAddress(Node $node): string
    {
        $address = is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';

        if ($address === '') {
            throw new OperatorNodeManagementException(
                'node.wireguard_address_missing',
                'Node has no WireGuard address for gateway SSH.',
            );
        }

        return $address;
    }
}
