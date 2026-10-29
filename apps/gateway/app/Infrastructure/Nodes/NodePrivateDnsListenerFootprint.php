<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Infrastructure\AppDev\PrivateDnsListenerUnitRenderer;
use App\Infrastructure\AppDev\VpnDnsmasqBackendListen;
use App\Models\Node;

/** Renders the listener release and units. Route publication owns records and catalog data. */
final readonly class NodePrivateDnsListenerFootprint
{
    public function __construct(private PrivateDnsListenerUnitRenderer $units) {}

    /** @return array{release_id: string, files: list<array{path: string, contents: string, sha256: string}>, service: string, socket: string} */
    public function input(Node $node): array
    {
        if (! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException('node.private_dns_listener_invalid', 'The private DNS listener needs a WireGuard address.', 422);
        }
        $release = PrivateDnsListenerRelease::fromGateway();
        $files = [];
        foreach ($release->files() as $path => $contents) {
            $files[] = ['path' => $path, 'contents' => $contents, 'sha256' => hash('sha256', $contents)];
        }
        $id = $release->id();

        return ['release_id' => $id, 'files' => $files,
            'service' => $this->units->render('/usr/bin/php8.5', '/var/lib/orbit/private-dns/releases/'.$id, $node->wireguard_ip,
                53, '/var/lib/orbit/private-dns/catalog.json', VpnDnsmasqBackendListen::Address.':53'),
            'socket' => $this->units->renderSocket($node->wireguard_ip, 53)];
    }
}
