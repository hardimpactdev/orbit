<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A job on the Gateway queue connection. Its constructor picks the queue with {@see self::onTaskVmQueue()}. */
trait TaskVmJob
{
    use Queueable;

    private function onTaskVmQueue(GatewayQueue $queue = GatewayQueue::Tasks): void
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue($queue->value);
    }
}
