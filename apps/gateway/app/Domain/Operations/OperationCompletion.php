<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Projects an executor receipt onto Gateway-owned intent. Never performs Node work. */
interface OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void;
}
