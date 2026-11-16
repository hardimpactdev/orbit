<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Instance;

/**
 * Refuses every task workspace command over SSH. Such a workspace lived on a task VM, and task VMs are removed, so no command
 * reaches one. The SSH task workspace path goes in a later slice (docs/reference/old-paths.md#the-task-vm-readers).
 */
final class TaskVmWorkspaceGuard
{
    public static function assertOwnedWorkspace(Instance $workspace): void
    {
        throw new TaskVmException(TaskVmError::WorkspaceMismatch,
            "Instance [{$workspace->name}] is no task workspace that Orbit can reach. Task VMs are removed, so no task workspace command runs over SSH.");
    }
}
