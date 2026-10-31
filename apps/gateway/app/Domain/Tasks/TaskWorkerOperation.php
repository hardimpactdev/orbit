<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;

/** Existing task VM workspace selection; Swamp owns worker execution. */
final readonly class TaskWorkerOperation
{
    public static function requireWorkspace(Task $group): Instance
    {
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if (! $instance instanceof Instance || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id
            || ! in_array($instance->status, [InstanceState::SourceResolved, InstanceState::Active], true)) {
            throw new ResourceOperationException('tasks.no_workspace', 'The group has no task VM workspace.', 409);
        }

        return $instance;
    }
}
