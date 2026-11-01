<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Board-only cancellation for untouched generated groups. Workspace cleanup belongs to tasks.cancel. */
final readonly class CancelTaskGroupAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    public function execute(Task $group): Task
    {
        $this->extension->execute();
        app(TaskOperations::class)->cancelOpen($group);

        return app(TaskExecutionLock::class)->synchronized($group->id, fn (): Task => DB::transaction(function () use ($group): Task {
            $group = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            TaskCancelIntent::eligible($group);
            if ($group->taskable_id !== null || TaskVm::forGroup($group) !== null) {
                throw new ResourceOperationException('tasks.native_cleanup_required', 'Use the keyed tasks.cancel Operation to clean up this workspace.', 409);
            }
            $group->update(['status' => TaskGroupStatus::Cancelled, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
                ->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);

            return $group->refresh()->load(['project', 'tasks', 'taskable']);
        }), wait: false);
    }
}
