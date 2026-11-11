<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Settle deferred admission when a timed-out run ends or its stale target hold is released, keeping the timeout result. */
interface DirectOperationTimeoutSettlement
{
    /** Called in the transaction holding the Operation lock; the family locks its target before settling admission. */
    public function timeoutSettled(Operation $operation): void;
}
