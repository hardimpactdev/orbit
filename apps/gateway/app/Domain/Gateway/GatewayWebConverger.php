<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Infrastructure\Gateway\NativeGatewayWebConverger;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;

#[Bind(NativeGatewayWebConverger::class)]
#[Singleton]
interface GatewayWebConverger
{
    /** Converges the Gateway site on the Gateway's own Node, which the Node Caddy build then publishes. */
    public function converge(Node $node, string $hostname, string $wireguardIp): void;
}
