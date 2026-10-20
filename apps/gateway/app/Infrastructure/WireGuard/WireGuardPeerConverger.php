<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeWireGuardPeerConverger::class)]
interface WireGuardPeerConverger
{
    public function converge(
        Node $node,
        SshConnection $connection,
        bool $rolelessOperator = false,
    ): void;
}
