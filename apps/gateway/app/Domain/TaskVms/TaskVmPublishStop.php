<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStopPending;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\Task;
use App\Models\TaskVm;

/** A queued push can be withdrawn; a started push must first record its terminal result. */
final readonly class TaskVmPublishStop
{
    public function __construct(private TaskWorkspaceLease $leases) {}

    public function stop(Task $group, string $commit, string $branch): bool
    {
        $vm = TaskVm::query()->where('group_id', $group->id)->where('publish_commit', $commit)->where('publish_branch', $branch)->first();
        if (! $vm instanceof TaskVm) {
            return false;
        }
        $cancelled = TaskVm::query()->whereKey($vm->id)->where('publish_commit', $commit)->where('publish_branch', $branch)
            ->where('publish_state', TaskVmPublishState::Queued)->whereNull('publish_started_at')->update(['publish_state' => null]);
        if ($cancelled === 1) {
            $this->leases->cancelQueued($group, 'publish-vm:'.$vm->id.':'.$commit);
        } elseif ($vm->refresh()->publish_state === TaskVmPublishState::Queued) {
            throw new OperationStopPending;
        }

        return $vm->refresh()->publish_state === TaskVmPublishState::Pushed;
    }
}
