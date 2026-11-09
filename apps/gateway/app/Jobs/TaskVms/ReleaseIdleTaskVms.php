<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Tasks\TaskVmIdleRelease;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Releases the task VMs of open groups that have run no Operation for a while (docs/reference/tasks.md#release-an-idle-task-vm). */
final class ReleaseIdleTaskVms implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onTaskVmQueue();
    }

    public function handle(TaskVmIdleRelease $release): void
    {
        $release->release();
    }
}
