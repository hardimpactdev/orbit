<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one attempt of an operation on the `operations` queue (docs/reference/operations.md). A second copy of the job
 * for the same operation does nothing, because only one worker claims the queued operation. A lost worker is
 * recovered by the Swamp observation recovery schedule, not by the queue's retry.
 */
final class RunOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Stays below the connection's `retry_after` of 1800 seconds, above the operation deadline of 1600. */
    public int $timeout = 1700;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(OperationRunner $runner): void
    {
        $runner->run($this->operationId);
    }
}
