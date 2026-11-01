<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class WireGuardServerConfigRenderer
{
    /** @param Collection<int, Node> $nodes */
    public function render(VpnConfiguration $configuration, Collection $nodes): string
    {
        return $this->renderForHub($configuration->server, $configuration->serverAddress, $configuration->port, $configuration->serverPrivateKey, $nodes);
    }

    /** @param Collection<int, Node> $nodes */
    public function renderForHub(Node $hub, string $address, int $port, #[\SensitiveParameter] string $privateKey, Collection $nodes): string
    {
        $peers = [];
        foreach ($nodes->sortBy('id') as $node) {
            if (
                $node->status === LifecycleStatus::Removing
                || $node->is($hub)
                || $node->wireguard_ip === null
                || $node->wireguard_public_key === null
            ) {
                continue;
            }

            $peers[] = ['name' => $node->name, 'public_key' => $node->wireguard_public_key, 'address' => $node->wireguard_ip.'/32'];
        }

        return $this->renderForPeers($address, $port, $privateKey, $peers);
    }

    /** @param list<array{name:string,public_key:string,address:string}> $peers Ordered, admitted public peer facts. */
    public function renderForPeers(string $address, int $port, #[\SensitiveParameter] string $privateKey, array $peers): string
    {
        $lines = [
            '[Interface]',
            "Address = {$address}",
            "ListenPort = {$port}",
            "PrivateKey = {$privateKey}",
        ];
        foreach ($peers as $peer) {
            $lines = [
                ...$lines,
                '',
                '[Peer]',
                "# {$peer['name']}",
                "PublicKey = {$peer['public_key']}",
                "AllowedIPs = {$peer['address']}",
            ];
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
