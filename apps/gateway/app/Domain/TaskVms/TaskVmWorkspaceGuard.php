<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

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
     * fixed across claims so imported CI caches retain their absolute paths.
     */
    public const string WorkspaceCheckout = 'workspace';

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

    /** When a group owns this workspace, the workspace must be on that group's ready task VM. */
    public static function assertOwnedWorkspace(Instance $workspace): void
    {
        $group = Task::topLevel()->find($workspace->task_workspace_owner_id);
        if ($group instanceof Task) {
            self::assertWorkspace($group, $workspace);
        }
    }

    /** An Instance on a task VM Node must be the workspace that the VM's group owns, in the group's Project. A pool VM serves none. */
    public static function assertInstance(Instance $instance): void
    {
        $vm = TaskVm::forNode($instance->node_id);
        if (! $vm instanceof TaskVm) {
            return;
        }

        $group = $vm->group;
        if (! $group instanceof Task) {
            throw new TaskVmException(TaskVmError::ForeignInstance, "Node [{$instance->node_id}] is an unassigned task VM. It serves no Instance.");
        }
        if ($instance->project_id !== $group->project_id || $instance->task_workspace_owner_id !== $group->id) {
            throw new TaskVmException(TaskVmError::ForeignInstance, "Node [{$instance->node_id}] is a task VM and serves only the workspace of task group [{$group->id}].");
        }
    }
}
