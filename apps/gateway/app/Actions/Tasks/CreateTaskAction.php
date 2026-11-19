<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;

final readonly class CreateTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(Task $group, CreateTaskData $data): Task
    {
        $this->requireExtension->execute();

        $status = $group->refresh()->status;
        if (in_array($status, TaskGroupStatus::endedStatuses(), true)) {
            throw TaskGroupGuard::groupClosed();
        }

        // A task with no subtask holds its own work; an outcome found later is a new task (docs/reference/tasks.md#a-task-with-no-subtask).
        if ($group->work()->exists()) {
            throw new ResourceOperationException('tasks.own_work', 'A task with no subtask takes no subtask. Create a new task for the new outcome.', 409);
        }

        if ($data->deliverables === [] && $status !== TaskGroupStatus::Backlog) {
            throw TaskGroupGuard::deliverablesRequired();
        }

        $position = StoredInteger::fromOrZero($group->tasks()->max('position')) + 1;

        return Task::query()->create([
            'parent_id' => $group->id,
            'position' => $position,
            'title' => $data->title,
            'brief' => $data->brief,
            'deliverables' => $data->deliverables,
            'status' => TaskStatus::Todo,
        ]);
    }
}
