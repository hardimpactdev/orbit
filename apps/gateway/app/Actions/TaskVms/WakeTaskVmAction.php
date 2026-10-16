<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\TaskWorkspaceOwnership;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * The gate before work that needs a group's task VM. Work goes on unless the VM is `parked`. A parked VM is
 * queued to start, and the work waits for a later tick: `ResumeTaskVm` sets the row `ready` once the Node agent
 * and Pi answer. Only `parked` waits here, so every other group and Node keeps today's behaviour.
 */
final readonly class WakeTaskVmAction
{
    /** The wait reason a group shows while its task VM starts again. */
    public const string Resuming = 'Task VM: resuming.';

    /** Whether the group's work may use its task VM now. The group shows `Task VM: resuming.` while it waits. */
    public function forGroup(Task $group): bool
    {
        $awake = $this->forVm(TaskVm::forGroup($group));
        if (! $awake && $group->capacity_wait_reason !== self::Resuming) {
            $group->update(['capacity_wait_reason' => self::Resuming]);
        } elseif ($awake && $group->capacity_wait_reason === self::Resuming) {
            $group->update(['capacity_wait_reason' => null]);
        }

        return $awake;
    }

    /** Whether work on this Instance may use its Node now: false only while the Node is a parked task VM. */
    public function forInstance(Instance $instance): bool
    {
        return $this->forVm(TaskVm::forNode($instance->node_id));
    }

    /** Whether this VM is awake; a parked VM queues one resume through the shared wake gate. */
    public function forVm(?TaskVm $vm): bool
    {
        if ($vm?->state !== TaskVmState::Parked) {
            return true;
        }
        if (! TaskWorkspaceOwnership::ownsVmWorkspaces($vm)) {
            return false;
        }
        ResumeTaskVm::dispatch($vm->id);

        return false;
    }
}
