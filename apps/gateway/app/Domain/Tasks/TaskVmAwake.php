<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;

/**
 * The gate of an admission that needs the task VM of a group. A parked VM is admitted to its resume, and the admission refuses
 * until it runs, so the call never starts a run against a stopped machine. Call it before any transaction: the refusal must
 * not roll back the resume it just queued.
 */
final class TaskVmAwake
{
    public static function require(Task $group): void
    {
        if (! app(WakeTaskVmAction::class)->forGroup($group)) {
            throw new ResourceOperationException('tasks.vm_resuming', 'The task VM was parked. Its resume is admitted; repeat the call when the VM runs.', 409);
        }
    }
}
