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
    /** A queued operation whose job has not started after this long counts as lost once the queue holds no job. */
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
        // Count before this sweep queues anything. An operation that waits has its job in the queue, so the queue is
        // empty only when every waiting operation's job is gone.
        $jobsGone = $this->dispatcher->jobs() === 0;

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

            if ($this->dispatch($operation)) {
                $count++;
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
