<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * Guards workspace ownership on task VMs: a Node with a live task VM serves only its group's
 * workspace, and a `vm` group works only in that workspace. A live task VM is any row that is
 * not Destroyed. Placement of the VMs themselves is {@see TaskVmPlacementPolicy}.
 */
final class TaskVmWorkspaceGuard
{
    /**
     * The checkout of the one workspace on a task VM, below the Project's directory in the apps root. The name is
     * fixed, unlike `task-<group id>` on a shared Node, so the workspace template is built at the same path.
     */
    public const string WorkspaceCheckout = 'workspace';

    /**
     * A `vm` group's workspace must be its `task-<group id>` Instance in the group's Project, on
     * the Node of its Ready task VM. Any other group's workspace must not be on a task VM Node.
     * A parked VM fails with `task_vm.parked`: no command reaches a VM that is stopped or starting.
     */
    public static function assertWorkspace(Task $group, Instance $workspace): void
    {
        if ($group->task_compute !== TaskCompute::Vm) {
            if (TaskVm::forNode($workspace->node_id) instanceof TaskVm) {
                throw new TaskVmException(TaskVmError::WorkspaceMismatch, "Task group [{$group->id}] does not own the task VM that serves its workspace.");
            }

            return;
        }

        $vm = TaskVm::forGroup($group);
        if ($vm?->state === TaskVmState::Parked) {
            throw new TaskVmException(TaskVmError::Parked, "The task VM [{$vm->name}] of task group [{$group->id}] is parked. Orbit starts it for the group's next work.");
        }
        if (! $vm instanceof TaskVm || $vm->state !== TaskVmState::Ready || $vm->node_id === null
            || $workspace->node_id !== $vm->node_id || $workspace->project_id !== $group->project_id
            || $workspace->name !== TaskWorkspaceName::for($group)) {
            throw new TaskVmException(TaskVmError::WorkspaceMismatch, "Task group [{$group->id}] has no workspace on its ready task VM.");
        }
    }

    /** Whether `assertWorkspace` accepts the workspace. */
    public static function allowsWorkspace(Task $group, Instance $workspace): bool
    {
        try {
            self::assertWorkspace($group, $workspace);

            return true;
        } catch (TaskVmException) {
            return false;
        }
    }

    /** When a `vm` group owns this workspace, the workspace must be on that group's ready task VM. */
    public static function assertOwnedWorkspace(Instance $workspace): void
    {
        $group = Task::topLevel()->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)
            ->where('task_compute', TaskCompute::Vm->value)->first();
        if ($group instanceof Task) {
            self::assertWorkspace($group, $workspace);
        }
    }

    /** An Instance on a task VM Node must be that group's `task-<group id>` workspace in the group's Project. A pool VM serves none. */
    public static function assertInstance(Instance $instance): void
    {
        $vm = TaskVm::forNode($instance->node_id);
        if (! $vm instanceof TaskVm) {
            return;
        }

        $group = $vm->group;
        if (! $group instanceof Task) {
            throw new TaskVmException(TaskVmError::ForeignInstance, "Node [{$instance->node_id}] is a warm task VM. It serves no Instance until a task group claims it.");
        }
        if ($instance->project_id !== $group->project_id || $instance->name !== TaskWorkspaceName::for($group)) {
            throw new TaskVmException(TaskVmError::ForeignInstance, "Node [{$instance->node_id}] is a task VM and serves only the workspace of task group [{$group->id}].");
        }
    }
}
