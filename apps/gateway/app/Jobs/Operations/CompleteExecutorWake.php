<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Power\ExecutorWake;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Projects a recorded result now, or recovers a missing result when the native deadline passes. */
final class CompleteExecutorWake implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationsPrompt->value);
    }

    public function handle(ExecutorWake $wake): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null || ! ExecutorWake::owns($operation)) {
            return;
        }
        if (! $operation->status->terminal() && $operation->executor_result === null && ExecutorWake::completionDeadline($operation)?->isFuture()) {
            $this->release(1);

            return;
        }
        $wake->completeHandoff($operation);
    }
}
