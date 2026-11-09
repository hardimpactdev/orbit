<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Models\Node;

final readonly class VpnConfiguration
{
    public function __construct(
        public Node $server,
        public string $subnet,
        public int $prefixLength,
        public int $port,
        public string $endpoint,
        public string $dnsServer,
        public string $domain,
        public string $serverAddress,
        public string $peerAddress,
        public string $serverPrivateKey,
        public string $serverPublicKey,
        /** @var array{address:string,source:string,fingerprint:string|null}|null The LAN addresses to prove when the endpoint is the hub's LAN address. */
        public ?array $lan = null,
    ) {}
}
