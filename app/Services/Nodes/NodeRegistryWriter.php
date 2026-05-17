<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;

final readonly class NodeRegistryWriter
{
    public function __construct(
        private DnsmasqReconciler $dnsmasqReconciler,
    ) {}

    public function writeNodeIdentity(
        string $name,
        string $legacyRole,
        ?string $environment,
        ?string $tld,
        string $platform,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $user,
        string $orbitPath,
    ): Node {
        return Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'role' => $legacyRole,
                'environment' => $environment,
                'tld' => $tld,
                'platform' => $platform,
                'host' => $host,
                'wireguard_address' => $wireguardAddress,
                'gateway_endpoint' => $gatewayEndpoint,
                'user' => $user,
                'orbit_path' => $orbitPath,
                'status' => 'active',
            ],
        );
    }

    public function writeAppNode(
        string $name,
        string $environment,
        ?string $tld,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $sshUser,
        string $user,
    ): Node {
        $node = $this->writeNodeIdentity(
            name: $name,
            legacyRole: 'app',
            environment: $environment,
            tld: $tld,
            platform: 'ubuntu',
            host: $host,
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            user: $user,
            orbitPath: "/home/{$user}/orbit",
        );

        if (config('orbit.is_gateway') === true) {
            $this->dnsmasqReconciler->reconcile();
        }

        return $node;
    }
}
