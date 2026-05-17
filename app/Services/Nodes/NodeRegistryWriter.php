<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;

final class NodeRegistryWriter
{
    public function __construct(
        private readonly DnsmasqReconciler $dnsmasqReconciler,
    ) {}

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
        $node = Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'role' => 'app',
                'environment' => $environment,
                'tld' => $tld,
                'platform' => 'unknown',
                'host' => $host,
                'wireguard_address' => $wireguardAddress,
                'gateway_endpoint' => $gatewayEndpoint,
                'user' => $user,
                'orbit_path' => "/home/{$user}/orbit",
                'status' => 'active',
            ],
        );

        if (config('orbit.is_gateway') === true) {
            $this->dnsmasqReconciler->reconcile();
        }

        return $node;
    }
}
