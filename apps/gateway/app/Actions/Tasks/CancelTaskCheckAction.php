<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * Stops a running Project check. The next tick gives the implementer its reminder
 * ([ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off)).
 */
final readonly class CancelTaskCheckAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskCheckRunner $checks,
        private TaskBroadcasts $broadcasts,
    ) {}

    public function execute(Task $group, Task $task, ?TaskCheck $check = null): TaskCheck
    {
        $this->requireExtension->execute();

        return app(TaskExecutionLock::class)->synchronized($group->id, fn (): TaskCheck => $this->cancelWithLock($group->fresh(['taskable']) ?? $group, $task, $check), wait: false);
    }

    private function cancelWithLock(Task $group, Task $task, ?TaskCheck $check): TaskCheck
    {
        $statuses = $check === null ? [TaskCheckStatus::Running] : [TaskCheckStatus::Running, TaskCheckStatus::Lost];
        $check = $check?->refresh() ?? $task->checks()->where('status', TaskCheckStatus::Running->value)->latest('id')->first();
        $instance = $group->taskable;
        if (! $check instanceof TaskCheck || ! $instance instanceof Instance) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_not_running',
                message: __('The task has no running check.'),
                status: 409,
            );
        }
        try {
            // A zero PID is an unstarted baseline claim. Its starter observes cancellation
            // and stops any process whose identity arrives after this locked request.
            if ($check->pid > 0) {
                $this->checks->cancel($instance, $check->process());
                app(TaskWorkspaceLease::class)->invalidateCheck($instance, $check->process());
                $reading = $this->checks->read($instance, $check->process());
                if ($reading->state !== 'finished') {
                    throw new TaskCheckException($reading->state === 'running'
                        ? 'The check process is still running.'
                        : 'The check supervisor is gone without a result. A child command may still be running.');
                }
            }
        } catch (TaskCheckException $exception) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_unreachable',
                message: __('The check remains running because its process could not be confirmed stopped: :reason', ['reason' => $exception->getMessage()]),
                status: 502,
            );
        }
        $claimed = TaskCheck::query()->whereKey($check->id)->whereIn('status', $statuses)
            ->update(['status' => TaskCheckStatus::Cancelled->value, 'finished_at' => now()]);
        if ($claimed === 0) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_not_running',
                message: __('The task has no running check.'),
                status: 409,
            );
        }
        $this->broadcasts->groupChanged($group->id);

        return $check->refresh();
    }
}
