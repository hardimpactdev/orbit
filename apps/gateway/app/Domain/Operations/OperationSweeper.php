<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds operations that a lost worker or a lost job left behind and queues their job again
 * (docs/reference/operations.md#recover-a-lost-worker). The next worker decides whether the interrupted step resumes
 * or fails, so the sweeper never finishes an operation itself.
 */
final readonly class OperationSweeper
{
    /** A queued job that has not started after this long counts as lost. */
    public const int QueuedJobLostSeconds = 600;

    public function __construct(
        private OperationStore $store,
        private OperationDispatcher $dispatcher,
        private OperationWorker $worker,
    ) {}

    /** @return int the number of operations queued again */
    public function sweep(): int
    {
        $requeued = 0;

        foreach (Operation::query()->where('status', OperationStatus::Running->value)->orderBy('id')->get() as $operation) {
            if (is_string($operation->worker) && $this->worker->alive($operation->worker)) {
                continue;
            }

            if ($this->store->requeue($operation) && $this->dispatch($operation)) {
                $requeued++;
            }
        }

        $lostBefore = Carbon::now()->subSeconds(self::QueuedJobLostSeconds);

        foreach (Operation::query()->where('status', OperationStatus::Queued->value)->orderBy('id')->get() as $operation) {
            if ($operation->dispatched_at !== null && $operation->dispatched_at->greaterThan($lostBefore)) {
                continue;
            }

            if ($this->dispatch($operation)) {
                $requeued++;
            }
        }

        return $requeued;
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
