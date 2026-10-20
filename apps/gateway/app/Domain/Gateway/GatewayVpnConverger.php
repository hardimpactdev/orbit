<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Data\Gateway\BootstrapGatewayData;
use App\Infrastructure\WireGuard\NativeGatewayVpnConverger;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;

#[Bind(NativeGatewayVpnConverger::class)]
#[Singleton]
interface GatewayVpnConverger
{
    public function converge(Node $gateway, BootstrapGatewayData $data): void;
}
