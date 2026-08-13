<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Vpn\VpnDnsSwarmInstaller;
use Closure;

final class NodeRemoveVpnInstallerFake extends VpnDnsSwarmInstaller
{
    /** @var list<array{name: string, public_key: string}> */
    public array $calls = [];

    public function __construct(
        private readonly ?Closure $removePeer = null,
    ) {}

    #[\Override]
    public function removePeer(string $name, string $publicKey): void
    {
        $this->calls[] = [
            'name' => $name,
            'public_key' => $publicKey,
        ];

        if ($this->removePeer instanceof Closure) {
            ($this->removePeer)($name, $publicKey);
        }
    }
}
