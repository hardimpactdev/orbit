<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds operations that a lost attempt or a lost job left behind and queues their job again
 * (docs/reference/operations.md#recover-a-lost-worker). The next worker decides whether the interrupted step resumes
 * or fails, so the sweeper never finishes an operation itself.
 */
final readonly class OperationSweeper
{
    /** A queued operation can be recovered after this long when no waiting job or held attempt lease explains the wait. */
    public const int QueuedJobLostSeconds = 600;

    public function __construct(
        private OperationStore $store,
        private OperationDispatcher $dispatcher,
        private OperationAttemptLease $lease,
    ) {}

    /** @return int the number of operations queued again */
    public function sweep(): int
    {
        $count = 0;
        // A reservation can survive a discarded pop while its worker lives on. Only jobs that still wait for a
        // worker block queued recovery; the attempt lease protects code that actually runs.
        $jobsGone = $this->dispatcher->waitingJobs() === 0;

        foreach (Operation::query()->where('status', OperationStatus::Running->value)->orderBy('id')->get() as $operation) {
            $requeued = false;
            // A held lease means an attempt still runs. A free one means its worker died or its code ended without
            // recording the outcome. The lease is released before the dispatch, so the next worker can take it at once.
            $this->lease->hold($operation->id, function () use ($operation, &$requeued): void {
                $operation->refresh();
                $requeued = $operation->status === OperationStatus::Running && $this->store->requeue($operation);
            });

            if ($requeued && $this->dispatch($operation)) {
                $count++;
            }
        }

        $lostBefore = Carbon::now()->subSeconds(self::QueuedJobLostSeconds);

        foreach (Operation::query()->where('status', OperationStatus::Queued->value)->orderBy('id')->get() as $operation) {
            if (! $jobsGone || ($operation->dispatched_at !== null && $operation->dispatched_at->greaterThan($lostBefore))) {
                continue;
            }

            $eligible = false;
            $this->lease->hold($operation->id, function () use ($operation, $lostBefore, &$eligible): void {
                $operation->refresh();
                $eligible = $operation->status === OperationStatus::Queued
                    && ($operation->dispatched_at === null || $operation->dispatched_at->lessThanOrEqualTo($lostBefore));
            });

            // Release the lease before publishing: an idle worker can consume the replacement during dispatch.
            if ($eligible && $this->dispatch($operation)) {
                $count++;
            }
        }

        foreach (Operation::query()->where('status', OperationStatus::Waiting->value)->orderBy('id')->get() as $operation) {
            $eligible = false;
            $this->lease->hold($operation->id, function () use ($operation, &$eligible): void {
                $operation->refresh();

                if ($operation->status !== OperationStatus::Waiting || $operation->next_read_at === null
                    || $operation->wait_delay_seconds === null
                    || ! $operation->next_read_at->copy()->addSeconds(2 * $operation->wait_delay_seconds)->isPast()) {
                    return;
                }

                $this->store->change($operation, ['next_read_at' => Carbon::now()]);
                $eligible = true;
            });

            if ($eligible) {
                try {
                    $this->dispatcher->dispatchRead($operation);
                    $count++;
                } catch (Throwable $exception) {
                    Log::error('The operation sweep could not queue a waiting read.', ['operation' => $operation->id, 'error' => $exception->getMessage()]);
                }
            }
        }

        return $count;
    }

    private function dispatch(Operation $operation): bool
    {
        try {
            $this->dispatcher->dispatch($operation);

            return true;
        } catch (Throwable $exception) {
            Log::error('The operation sweep could not queue an operation.', ['operation' => $operation->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }
}
