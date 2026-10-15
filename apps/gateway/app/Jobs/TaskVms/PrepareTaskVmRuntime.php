<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\PrepareTaskVmRuntimeAction;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Prepares Pi on the enrolled VM of a group, then marks the task VM `ready` for the next claim
 * ({@see PrepareTaskVmRuntimeAction}).
 */
final class PrepareTaskVmRuntime implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 900;

    /** Only a confirmed pre-submit failure releases our lock; an uncertain submission must stay unique. */
    public static function enqueue(int $taskVmId): void
    {
        $job = new self($taskVmId);
        $lock = new UniqueLock(Cache::store());
        if (! $lock->acquire($job)) {
            return;
        }

        try {
            // Resolving the connection cannot enqueue this job. Failure here is a confirmed rejection.
            Queue::connection($job->connection);
        } catch (Throwable $exception) {
            // Never fall back to forceRelease, even if a store did not supply an owner.
            if ($job->uniqueLockOwner !== '') {
                $lock->release($job);
            }
            throw $exception;
        }

        // Use the very job that owns the lock. Bus dispatch does not acquire a second unique lock.
        // Once submission begins, a lost Redis reply or a post-push event may throw after acceptance.
        // Keep the lock in that case: the accepted job finishes/fails, or its orphan lock expires.
        Bus::dispatch($job);
    }

    public function handle(PrepareTaskVmRuntimeAction $prepare): void
    {
        $prepare->execute($this->taskVmId);
    }
}
