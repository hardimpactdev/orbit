<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use Closure;

interface RecoverableNodeConverger
{
    /**
     * @param  Closure(NodeObservation): void  $completion
     * @param  (Closure(NodeObservation): void)|null  $recordObservation  Runs before agent installation or networking.
     */
    public function convergeRecoverably(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint,
        Closure $completion,
        bool $rolelessOperator = false,
        ?Closure $recordObservation = null,
    ): void;
}
