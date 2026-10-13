<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

/**
 * A job for one task VM row, on the `task-vms` queue. A unique job is unique per row. Each job sets its own
 * `uniqueFor`, because a lost lock must expire for some jobs and never for others.
 */
trait TaskVmRowJob
{
    use TaskVmJob;

    public function __construct(public int $taskVmId)
    {
        $this->onTaskVmQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }
}
