<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** A family domain invariant checked before recording cancellation intent. */
interface OperationCancelGuard
{
    public function assertCancellable(Operation $operation): void;
}
