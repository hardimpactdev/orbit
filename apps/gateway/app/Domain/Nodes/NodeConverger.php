<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeNodeConverger::class)]
interface NodeConverger
{
    public function converge(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint = null,
        bool $rolelessOperator = false,
    ): NodeObservation;
}
