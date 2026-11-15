<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/** A published task holds no task VM: its publish releases the VM. */
final readonly class TaskVmReentry
{
    public static function release(Operation $operation): void
    {
        if ($operation->status !== OperationStatus::Succeeded) {
            return;
        }
        $group = Task::topLevel()->find($operation->target_id);
        $vm = $group instanceof Task ? TaskVm::forGroup($group) : null;
        if ($vm instanceof TaskVm) {
            $vm->update(['state' => TaskVmState::Releasing]);
            ReleaseTaskVmSlot::dispatch($vm->id, $group->id)->afterCommit();
            $group->update(['status' => TaskGroupStatus::WaitingForReview, 'settled_at' => now()]);
        }
    }
}
