<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Releases a slot whose task start did not pass (docs/cli/task/vm-release.mmd): its VM, hub peer and Node go, so the slot
 * is free again. A slot that another group holds by then, or that is already released, is left alone.
 */
final class ReleaseTaskVmSlot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $slotId, public int $groupId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function uniqueId(): string
    {
        return (string) $this->slotId;
    }

    public function handle(TaskVmSlotRuns $runs): void
    {
        $slot = TaskVm::query()->find($this->slotId);
        if ($slot instanceof TaskVm && $slot->group_id === $this->groupId && $slot->state !== TaskVmState::Destroyed) {
            $runs->startRelease($slot, 'task-start');
        }
    }
}
