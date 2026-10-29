<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;

/** Physical effects retain their evidence on the Operation. VM state needs the parent's full contract. */
final readonly class TaskVmEffectCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}
