<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAgent;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrModeChoice;
use App\Domain\Tasks\TaskStatusMoves;
use App\Domain\Tasks\TaskType;
use App\Models\Task;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskExecutionLock $execution,
    ) {}

    public function execute(Task $group, UpdateTaskGroupData $data): Task
    {
        $this->requireExtension->execute();
        if ($data->status === TaskGroupStatus::Todo && $group->status === TaskGroupStatus::Backlog) {
            self::leavesBacklog($group->tasks()->get());
        }
        // The row lock makes a status move and a scheduler claim exclusive: whichever commits second sees the other's status.
        $updated = $this->execution->synchronized($group->id, fn (): Task => DB::transaction(static function () use ($group, $data): Task {
            $locked = Task::topLevel()->with('tasks')->lockForUpdate()->findOrFail($group->id);

            if (($data->title !== null || $data->brief !== null) && $locked->status !== TaskGroupStatus::Backlog) {
                throw TaskGroupGuard::notInBacklog();
            }

            if ($data->status !== null && $data->status !== $locked->status) {
                TaskStatusMoves::group($locked, $data->status);

                if ($data->status === TaskGroupStatus::Todo) {
                    self::leavesBacklog($locked->tasks);
                    // A group that leaves backlog now runs with the handoff gates (docs/reference/tasks.md#handoffs).
                    $locked->handoffs_gated = $locked->handoffs_gated || $locked->status === TaskGroupStatus::Backlog;
                }

                $locked->status = $data->status;
            }

            if ($data->preview !== null) {
                if (in_array($locked->status, TaskGroupStatus::endedStatuses(), true)) {
                    throw new ResourceOperationException('tasks.preview_closed', 'Preview cannot change after a task group has ended.', 409);
                }
                $locked->preview = $data->preview;
            }
            if ($data->prMode !== null && $data->prMode !== $locked->pr_mode) {
                TaskPrModeChoice::refuseLocked($locked);
                $locked->pr_mode = $data->prMode;
            }
            if ($data->agents !== []) {
                TaskAgent::assertChangeable($locked, $data->agents);
                $locked->fill(TaskAgent::groupColumns($data->agents));
            }
            $locked->title = $data->title ?? $locked->title;
            $locked->brief = $data->brief ?? $locked->brief;
            if ($locked->isDirty()) {
                // The work row of a task with no subtask repeats the task's title and brief (TaskType::Work).
                if ($locked->isDirty(['title', 'brief'])) {
                    $locked->work()->first()?->update(['title' => $locked->title, 'brief' => $locked->brief]);
                }
                $locked->save();
            }

            return $locked;
        }), wait: false);

        return $updated->fresh(['project', 'tasks', 'taskable']) ?? $updated;
    }

    /**
     * Applies UPDATE to the work of a task with no subtask. A task in backlog with no subtask and no work opens its work here, in
     * one transaction with UPDATE, so a refused update leaves the task as it was (docs/reference/tasks.md#a-task-with-no-subtask).
     *
     * @param  Closure(Task): Task  $update
     */
    public function work(Task $group, Closure $update): Task
    {
        $this->requireExtension->execute();
        // An existing work takes its update as a subtask does, with its repository reads outside any transaction.
        $existing = $group->work()->first();
        if ($existing instanceof Task) {
            return $update($existing);
        }

        return DB::transaction(static function () use ($group, $update): Task {
            $locked = Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $work = $locked->work()->first();
            if ($work instanceof Task) {
                return $update($work);
            }
            if ($locked->status !== TaskGroupStatus::Backlog) {
                throw TaskGroupGuard::notInBacklog();
            }
            if (Task::query()->where('parent_id', $locked->id)->exists()) {
                throw TaskGroupGuard::deliverablesLocked();
            }

            return $update(CreateTaskGroupAction::openWork($locked, []));
        });
    }

    /**
     * The rule of a task that leaves backlog, read from its rows: its work, or its subtasks (TaskGroupGuard::leavesBacklog).
     *
     * @param  Collection<int, Task>  $rows
     */
    public static function leavesBacklog(Collection $rows): void
    {
        $work = $rows->first(static fn (Task $task): bool => $task->type === TaskType::Work);
        $subtasks = [];
        foreach ($rows->reject(static fn (Task $task): bool => $task->type === TaskType::Work)->sortBy('position') as $task) {
            $subtasks['#'.$task->id.' "'.$task->title.'"'] = $task->deliverableList() !== [];
        }
        TaskGroupGuard::leavesBacklog($work instanceof Task ? $work->deliverableList() !== [] : null, $subtasks);
    }
}
