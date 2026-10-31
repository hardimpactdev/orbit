<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;

/** Records cancellation intent. Swamp stops its owned check and projects the terminal row. */
final readonly class CancelTaskCheckAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private CancelOperationAction $cancel) {}

    public function execute(Task $group, Task $task, ?TaskCheck $check = null): TaskCheck
    {
        $this->extension->execute();
        $checkId = $check?->id;
        $check = $task->checks()->whereIn('status', [TaskCheckStatus::Running, TaskCheckStatus::Lost])->when($checkId !== null, fn ($query) => $query->whereKey($checkId))->latest('id')->first();
        $operation = $check === null ? null : Operation::query()->where('kind', 'tasks.check')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('input->check_id', $check->id)->whereNotIn('status', OperationStatus::terminalValues())->first();
        if (! $check instanceof TaskCheck || ! $operation instanceof Operation) {
            throw new ResourceOperationException('tasks.check_not_running', 'The task has no running check.', 409);
        }
        $this->cancel->execute($operation);

        return $check->refresh();
    }
}
