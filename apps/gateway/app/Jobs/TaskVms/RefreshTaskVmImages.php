<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmImageRefreshTrigger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Admits the refresh of the task image of every Linux task VM host. Without a release it is the daily trigger and admits
 * every host; with the release that went live and the one it replaced, it admits them only when that release changed
 * playbooks, pins or lock files. The admission of a host returns its open refresh, so a repeat starts nothing.
 */
final class RefreshTaskVmImages implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 600;

    public function __construct(public ?string $releaseId = null, public ?string $previousId = null)
    {
        $this->onTaskVmQueue();
    }

    public function uniqueId(): string
    {
        return ($this->releaseId ?? 'daily').':'.($this->previousId ?? '');
    }

    public function handle(TaskVmImageRefreshTrigger $trigger): void
    {
        if ($this->releaseId === null || $this->previousId === null) {
            $trigger->admitAll('daily');

            return;
        }
        $trigger->afterRelease($this->releaseId, $this->previousId);
    }
}
