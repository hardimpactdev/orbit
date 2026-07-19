<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;

final readonly class NodeRegistryWriter
{
    public function __construct(
        private DnsmasqReconciler $dnsmasqReconciler,
    ) {}

    public function writeNodeIdentity(
        string $name,
        ?string $tld,
        string $platform,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $user,
        string $orbitPath,
        NodeStatus $status = NodeStatus::Active,
        ?PinnedHostKey $hostKey = null,
        ?string $architecture = null,
    ): Node {
        if ($status === NodeStatus::Active && ! NodeTld::isValid($tld)) {
            throw new RuntimeException('Active nodes require a valid non-reserved TLD.');
        }

        $attributes = [
            'tld' => $tld,
            'platform' => $platform,
            'host' => $host,
            'wireguard_address' => $wireguardAddress,
            'gateway_endpoint' => $gatewayEndpoint,
            'user' => $user,
            'orbit_path' => $orbitPath,
            'status' => $status,
        ];

        if ($hostKey instanceof PinnedHostKey) {
            $attributes = [
                ...$attributes,
                'host_key_type' => $hostKey->type,
                'host_key_fingerprint' => $hostKey->fingerprint,
                'host_key_public' => $hostKey->publicKey,
                'host_key_pin_mode' => $hostKey->pinMode,
                'host_key_pinned_at' => now(),
            ];
        }

        if ($architecture !== null) {
            $attributes['architecture'] = $architecture;
        }

        $node = Node::query()->updateOrCreate(
            ['name' => $name],
            $attributes,
        );

        if ($node->isActive()) {
            $this->dnsmasqReconciler->reconcileRecords();
        }

        return $node;
    }

    public function writeAppNode(
        string $name,
        ?string $tld,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $sshUser,
        string $user,
        NodeStatus $status = NodeStatus::Provisioning,
        ?PinnedHostKey $hostKey = null,
    ): Node {
        return $this->writeNodeIdentity(
            name: $name,
            tld: $tld,
            platform: 'ubuntu',
            host: $host,
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            user: $user,
            orbitPath: "/home/{$user}/orbit",
            status: $status,
            hostKey: $hostKey,
        );
    }

    public function markActive(Node $node): void
    {
        if (! NodeTld::isValid($node->tld)) {
            throw new RuntimeException('Active nodes require a valid non-reserved TLD.');
        }

        $node->forceFill(['status' => NodeStatus::Active])->save();
        $this->dnsmasqReconciler->reconcileRecords();
    }
}
