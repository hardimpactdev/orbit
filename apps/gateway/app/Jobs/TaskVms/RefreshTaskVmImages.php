<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmImageRefreshTrigger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Admits the refresh of the task image of a task VM host. With no host it is the daily trigger and admits every Linux host to the
 * release the Gateway runs; with a host and a commit it admits that host to the commit a release queued behind the refresh that has
 * just ended. The admission of a host returns its open refresh, so a repeat starts nothing.
 */
final class RefreshTaskVmImages implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 600;

    public function __construct(public ?int $hostId = null, public ?string $commit = null)
    {
        $this->onTaskVmQueue();
    }

    public function uniqueId(): string
    {
        return ($this->hostId === null ? 'daily' : 'host-'.$this->hostId).':'.($this->commit ?? '');
    }

    public function handle(TaskVmImageRefreshTrigger $trigger): void
    {
        if ($this->hostId === null || $this->commit === null) {
            $trigger->admitAll('daily');

            return;
        }
        $trigger->admitHost($this->hostId, 'release', $this->commit);
    }
}
