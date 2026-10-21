<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Jobs\RunOperation;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/** Queues the job of an operation on the `operations` queue of the Gateway queue connection. */
final readonly class OperationDispatcher
{
    public function available(): bool
    {
        return GatewayQueueRedis::configured();
    }

    /**
     * Jobs that still wait for a worker, including delayed jobs. A reservation alone does not prove its handler runs;
     * the operation's attempt lease owns that fact.
     */
    public function waitingJobs(): int
    {
        $queue = Queue::connection(GatewayQueue::Connection);

        return $queue->pendingSize(GatewayQueue::Operations->value) + $queue->delayedSize(GatewayQueue::Operations->value);
    }

    public function dispatch(Operation $operation): void
    {
        Queue::connection(GatewayQueue::Connection)->pushOn(GatewayQueue::Operations->value, new RunOperation($operation->id));
        $operation->forceFill(['dispatched_at' => Carbon::now()])->save();
    }
}
