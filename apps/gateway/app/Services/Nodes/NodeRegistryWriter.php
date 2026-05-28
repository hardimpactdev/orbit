<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Security\PinnedHostKey;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;

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
        string $status = Node::STATUS_ACTIVE,
        ?PinnedHostKey $hostKey = null,
    ): Node {
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

        return Node::query()->updateOrCreate(
            ['name' => $name],
            $attributes,
        );
    }

    public function writeAppNode(
        string $name,
        ?string $tld,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $sshUser,
        string $user,
        string $status = Node::STATUS_PROVISIONING,
        ?PinnedHostKey $hostKey = null,
    ): Node {
        $node = $this->writeNodeIdentity(
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

        if (config('orbit.is_gateway') === true) {
            $this->dnsmasqReconciler->reconcile();
        }

        return $node;
    }

    public function markActive(Node $node): void
    {
        $node->forceFill(['status' => Node::STATUS_ACTIVE])->save();
    }
}
