<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Instances\Power\ExecutorWake;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the Node's journal again for one Operation that ended under its fence (ADR 0266, "Reconcile by the journal"). At most one
 * such read per Operation is queued or running, so an unreachable Node cannot pile reads onto the operations queue.
 */
final class RereadExecutorJournal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** Frees the Operation for the next read if this job is lost before it runs. */
    public int $uniqueFor = 120;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function uniqueId(): string
    {
        return (string) $this->operationId;
    }

    public function handle(ExecutorWake $wake): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation !== null && ExecutorFence::heldAfterRun($operation)) {
            $wake->settleFenced($operation);
        } elseif ($operation !== null && ExecutorWake::owns($operation)) {
            $wake->completeHandoff($operation);
        }
    }
}
