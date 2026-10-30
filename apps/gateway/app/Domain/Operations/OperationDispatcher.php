<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Routes\DirectRoutePublication;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Routes\StartRoutePublicationRun;
use App\Jobs\ReadOperation;
use App\Jobs\RunOperation;
use App\Models\Operation;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

/** Queues the job of an operation on the `operations` queue of the Gateway queue connection. */
final readonly class OperationDispatcher
{
    public function available(): bool
    {
        return GatewayQueueRedis::configured();
    }

    /**
     * Start jobs that still wait for a worker, including delayed starts. Waiting reads do not block queued recovery. A reservation alone does not prove its handler runs;
     * the operation's attempt lease owns that fact.
     */
    public function waitingJobs(): int
    {
        $queue = Queue::connection(GatewayQueue::Connection);

        if (! $queue instanceof RedisQueue && ! $queue instanceof DatabaseQueue) {
            throw new LogicException('The operations queue must support job inspection.');
        }

        return $queue->pendingJobs(GatewayQueue::Operations->value)
            ->concat($queue->delayedJobs(GatewayQueue::Operations->value))
            ->reject(static fn (InspectedJob $job): bool => $job->name === ReadOperation::class)
            ->count();
    }

    public function dispatchRead(Operation $operation): void
    {
        $nextRead = $operation->next_read_at ?? throw new LogicException('A waiting read needs a due time.');
        Queue::connection(GatewayQueue::Connection)->laterOn(
            GatewayQueue::Operations->value,
            $nextRead,
            new ReadOperation($operation->id),
        );
    }

    public function dispatch(Operation $operation): void
    {
        if (DirectRoutePublication::handles($operation->kind)) {
            DB::afterCommit(static fn () => app(StartRoutePublicationRun::class)->start($operation));

            return;
        }
        if (app(OperationExecutors::class)->swamp($operation->kind)) {
            return;
        }

        Queue::connection(GatewayQueue::Connection)->pushOn(GatewayQueue::Operations->value, new RunOperation($operation->id));
        $operation->forceFill(['dispatched_at' => Carbon::now()])->save();
    }
}
