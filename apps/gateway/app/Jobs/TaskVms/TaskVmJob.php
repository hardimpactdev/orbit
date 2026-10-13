<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A job on the `task-vms` database connection. Its constructor picks the queue with {@see self::onTaskVmQueue()}. */
trait TaskVmJob
{
    use Queueable;

    private function onTaskVmQueue(TaskVmQueue $queue = TaskVmQueue::Vms): void
    {
        $this->onConnection(TaskVmQueue::Connection)->onQueue($queue->value);
    }
}
