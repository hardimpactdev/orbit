<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Optional on a direct projection whose records must keep what a run proved after its timeout, or that it never ran. */
interface DirectOperationLateResult
{
    /**
     * Runs once, in the transaction that records the verified native terminal of an Operation already failed with
     * `operation.timed_out`. `$result` is the run's terminal result, or `[]` when it cannot be read; the Operation's own status and
     * result stay as recorded. Record only what the result affirmatively shows.
     *
     * @param  array<string, mixed>  $result
     */
    public function late(Operation $operation, string $status, array $result): void;

    /**
     * Runs once, in the transaction that records that the Operation never ran: it was cancelled before its run started, or the lookup
     * for its lost start found no run in Swamp. Nothing reached the Node.
     */
    public function neverRan(Operation $operation): void;
}
