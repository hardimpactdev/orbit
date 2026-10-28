<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\DB;

final readonly class CancelRunningSubtaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension, private StopTaskSubtaskAction $stop) {}

    public function execute(Task $parent, Task $task): Task
    {
        $this->requireExtension->execute();
        app(TaskOperations::class)->cancelOpen($parent, $task->id);
        $candidate = Task::query()->where('parent_id', $parent->id)->findOrFail($task->id);
        $group = $parent->fresh() ?? $parent;
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)
            || ! in_array($candidate->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true)) {
            throw new ResourceOperationException('tasks.subtask_not_running', 'Only a todo, running, or reviewing subtask in an open group can be cancelled.', 409);
        }
        $this->stop->executeExternal($group, $candidate);

        $instanceId = $group->taskable_id;
        $finish = fn (): Task => DB::transaction(function () use ($parent, $candidate, $instanceId): Task {
            $group = Task::topLevel()->lockForUpdate()->findOrFail($parent->id);
            $locked = Task::query()->where('parent_id', $group->id)->lockForUpdate()->findOrFail($candidate->id);
            if ($locked->status !== $candidate->status || $group->taskable_id !== $instanceId || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                throw new ResourceOperationException('tasks.subtask_not_running', 'The subtask or group changed while Orbit stopped it, so its new state stands.', 409);
            }
            app(StopTaskSubtaskAction::class)->assertExternalStopped($group, $locked);
            TaskAssistance::resolveExternalSubtask($locked, $group);
            $this->markSubtaskCancelled($locked);

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        return $instanceId === null ? $finish() : app(TaskExecutionLock::class)->synchronized(-$instanceId, $finish, wait: false);
    }

    private function markSubtaskCancelled(Task $task): ?string
    {
        TaskCheck::query()->where('task_id', $task->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->update(['status' => TaskCheckStatus::Cancelled->value, 'finished_at' => now(), 'updated_at' => now()]);

        $assistanceReason = $task->assistance_reason;
        TaskAssistance::apply($task);
        $task->update([
            'status' => TaskStatus::Cancelled,
            'settled_at' => now(),
            'completion_summary' => 'Cancelled by operator.',
        ]);

        return $assistanceReason;
    }
}
