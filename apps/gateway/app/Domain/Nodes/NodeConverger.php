<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use Closure;

interface NodeConverger
{
    /** @param (Closure(NodeObservation): void)|null $recordObservation Runs before agent installation or networking. */
    public function converge(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint = null,
        bool $rolelessOperator = false,
        ?Closure $recordObservation = null,
    ): NodeObservation;
}
