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
     * The jobs on the `operations` queue that wait or run, as the queue driver counts them: Redis counts its waiting,
     * delayed, and reserved jobs, and the database driver every row of the queue.
     */
    public function jobs(): int
    {
        return Queue::connection(GatewayQueue::Connection)->size(GatewayQueue::Operations->value);
    }

    public function dispatch(Operation $operation): void
    {
        Queue::connection(GatewayQueue::Connection)->pushOn(GatewayQueue::Operations->value, new RunOperation($operation->id));
        $operation->forceFill(['dispatched_at' => Carbon::now()])->save();
    }
}
