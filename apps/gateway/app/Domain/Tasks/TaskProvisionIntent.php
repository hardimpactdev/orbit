<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstancePlacement;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;

/**
 * Where a task starts (docs/cli/task/start.mmd): a copy of its Project's default Instance. A Project whose default is no container
 * gets the refusal of the removed task VM runtime. The kind `tasks.provision` stays the request kind of the start route; no task VM
 * claim is left (docs/reference/old-paths.md).
 */
final readonly class TaskProvisionIntent
{
    /** A task starts only as a copy of its Project's default Instance. */
    public function start(Task $group, string $caller, ?string $request): Operation
    {
        InstancePlacement::taskCopy($group->project()->firstOrFail());

        return app(TaskCopyStart::class)->start($group, $caller, $request);
    }

    public function assertProvisionable(Task $group): void
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        if ($group->status === TaskGroupStatus::Backlog) {
            throw new ResourceOperationException('tasks.not_todo', 'Provision requires a todo task group.', 409);
        }
        if ($group->status !== TaskGroupStatus::Todo || $group->taskable_id !== null) {
            throw TaskGroupGuard::alreadyClaimed();
        }
    }
}
