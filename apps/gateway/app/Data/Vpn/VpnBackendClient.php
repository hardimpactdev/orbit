<?php

declare(strict_types=1);

namespace App\Data\Vpn;

final class VpnBackendClient
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $address,
        public bool $enabled,
        public readonly ?string $latestHandshakeAt,
        public readonly ?string $config = null,
    ) {}
}
