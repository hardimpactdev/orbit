<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads a waiting step's existing work. A due conditional claim prevents duplicate reads. */
final class ReadOperation implements ShouldQueue
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
        $runner->read($this->operationId);
    }
}
