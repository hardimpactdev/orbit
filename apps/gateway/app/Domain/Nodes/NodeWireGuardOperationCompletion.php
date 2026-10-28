<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;

/** The admitted public key remains desired state, including after a failed publication. */
final readonly class NodeWireGuardOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}
