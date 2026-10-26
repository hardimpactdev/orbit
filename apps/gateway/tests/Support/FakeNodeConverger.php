<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Models\Node;
use Closure;

final class FakeNodeConverger implements NodeConverger, RecoverableNodeConverger
{
    public function converge(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint = null, bool $rolelessOperator = false, ?Closure $recordObservation = null): NodeObservation
    {
        $observation = new NodeObservation('x86_64');
        $recordObservation?->__invoke($observation);

        return $observation;
    }

    public function convergeRecoverably(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint, Closure $completion, bool $rolelessOperator = false, ?Closure $recordObservation = null): void
    {
        $completion($this->converge($node, $identity, $expectedSshHostFingerprint, $rolelessOperator, $recordObservation));
    }
}
