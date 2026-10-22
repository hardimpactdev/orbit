<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** A kind with steps that release the worker while their work continues elsewhere. */
interface OperationWaitingKind extends OperationKind
{
    public function waitingStep(Operation $operation, string $name): OperationWaitingStep;
}
