<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use App\Infrastructure\WireGuard\NativeWireGuardPeerDnsRepairer;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeWireGuardPeerDnsRepairer::class)]
interface WireGuardPeerDnsRepairer
{
    public function repair(Node $node): void;
}
