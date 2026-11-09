<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;

/**
 * Work on a group needs its workspace. While the group's `tasks.provision` Operation waits for a place or runs, a turn, check,
 * publish, script or package install is refused with the Operation to follow (docs/reference/tasks.md#run-an-external-turn).
 */
final class TaskProvisionPending
{
    public static function refuse(Task $group): void
    {
        $provision = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('kind', 'tasks.provision')->whereNotIn('status', OperationStatus::terminalValues())->first(['id']);
        if ($provision instanceof Operation) {
            throw new ResourceOperationException('tasks.not_provisioned', "Task group {$group->id} is not provisioned yet. Follow Operation {$provision->id}, then repeat the call.", 409,
                details: ['operation_id' => $provision->id]);
        }
    }
}
