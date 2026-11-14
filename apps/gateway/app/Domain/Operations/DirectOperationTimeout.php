<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Optional on a direct projection whose records must leave their in-progress state when the outcome of its run is not known. */
interface DirectOperationTimeout
{
    /** Runs in the transaction that records `operation.timed_out` at budget expiry, or `operation.result_unreadable` for a run that
     * ended without a readable result; record the Operation's `error_code`. The native run may still be running after an expiry,
     * so record only the documented failure state; a later admission recovers it.
     */
    public function timedOut(Operation $operation): void;
}
