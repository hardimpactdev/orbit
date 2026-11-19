<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Power\ExecutorWake;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;

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
        $deadline = ExecutorWake::completionDeadline($operation);
        $left = $deadline === null ? 0 : $deadline->getTimestampMs() - now()->getTimestampMs();
        if (! $operation->status->terminal() && $operation->executor_result === null && $left > 0) {
            // The queue delays in whole seconds, so this job can start just before its deadline. It waits out that last second
            // itself: a released job can come back several seconds late, while a held visitor waits for the answer.
            if ($left > 1000) {
                $this->release(1);

                return;
            }
            Sleep::usleep($left * 1000);
        }
        $wake->completeHandoff($operation);
    }
}
