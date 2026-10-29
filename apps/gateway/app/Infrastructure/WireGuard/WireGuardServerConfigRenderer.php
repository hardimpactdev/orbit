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
        $lines = [
            '[Interface]',
            "Address = {$address}",
            "ListenPort = {$port}",
            "PrivateKey = {$privateKey}",
        ];

        foreach ($nodes->sortBy('id') as $node) {
            if (
                $node->status === LifecycleStatus::Removing
                || $node->is($hub)
                || $node->wireguard_ip === null
                || $node->wireguard_public_key === null
            ) {
                continue;
            }

            $lines = [
                ...$lines,
                '',
                '[Peer]',
                "# {$node->name}",
                "PublicKey = {$node->wireguard_public_key}",
                "AllowedIPs = {$node->wireguard_ip}/32",
            ];
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
