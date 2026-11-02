<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Optional on a direct projection whose records must leave their in-progress state at budget expiry. */
interface DirectOperationTimeout
{
    /** Runs in the expiry transaction after `operation.timed_out` is recorded. The native run may still be running,
     * so record only the documented failure state; a later admission recovers it.
     */
    public function timedOut(Operation $operation): void;
}
