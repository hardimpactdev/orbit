<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeGatewayPeerProjectionManager::class)]
interface GatewayPeerProjectionManager
{
    public function converge(Node $node): void;

    public function remove(Node $node): void;

    public function restore(Node $node): void;
}
