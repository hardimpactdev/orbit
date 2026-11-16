<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * Guards a task workspace command: a group works only in the workspace it owns on its ready task VM. No task VM
 * exists any more, so every command fails with `task_vm.workspace_mismatch`. The guard goes with the task VM
 * tables (docs/reference/old-paths.md#the-task-vm-readers).
 */
final class TaskVmWorkspaceGuard
{
    /**
     * A group's workspace must be the Instance it owns in its Project, on the Node of its Ready task VM. A parked VM
     * fails with `task_vm.parked`: no command reaches a VM that is stopped or starting.
     */
    public static function assertWorkspace(Task $group, Instance $workspace): void
    {
        $vm = TaskVm::forGroup($group);
        if ($vm?->state === TaskVmState::Parked) {
            throw new TaskVmException(TaskVmError::Parked, "The task VM [{$vm->name}] of task group [{$group->id}] is parked. Orbit starts it for the group's next work.");
        }
        if (! $vm instanceof TaskVm || $vm->state !== TaskVmState::Ready || $vm->node_id === null
            || $workspace->node_id !== $vm->node_id || $workspace->project_id !== $group->project_id
            || $workspace->task_workspace_owner_id !== $group->id) {
            throw new TaskVmException(TaskVmError::WorkspaceMismatch, "Task group [{$group->id}] has no workspace on its ready task VM.");
        }
    }

    /**
     * A task workspace is the Instance a group owns, on that group's ready task VM. An Instance without an owner is no
     * task workspace, so no task operation reaches it.
     */
    public static function assertOwnedWorkspace(Instance $workspace): void
    {
        $group = $workspace->task_workspace_owner_id === null ? null : Task::topLevel()->find($workspace->task_workspace_owner_id);
        if (! $group instanceof Task) {
            throw new TaskVmException(TaskVmError::WorkspaceMismatch, "Instance [{$workspace->name}] is not a task workspace.");
        }
        self::assertWorkspace($group, $workspace);
    }
}
