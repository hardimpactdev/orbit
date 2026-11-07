<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Releases a slot whose task start did not pass (docs/cli/tasks/vm-release.mmd): its VM, hub peer and Node go, so the slot
 * is free again. A slot that another group holds by then, or that is already released, is left alone.
 */
final class ReleaseTaskVmSlot implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $slotId, public int $groupId)
    {
        $this->onTaskVmQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->slotId;
    }

    public function handle(TaskVmSlotRuns $runs): void
    {
        $slot = TaskVm::query()->find($this->slotId);
        if ($slot instanceof TaskVm && $slot->group_id === $this->groupId && $slot->state !== TaskVmState::Releasing) {
            $runs->startRelease($slot, 'task-start');
        }
    }
}
