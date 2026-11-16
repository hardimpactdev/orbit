<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Task;

/** Stops remote work before cancellation. Call outside the cancellation transaction. */
final readonly class StopTaskSubtaskAction
{
    /** Identity of the work about to be stopped, for completion's locked revalidation. */
    public function snapshot(Task $group, Task $task): string
    {
        $fresh = $task->fresh() ?? $task;
        $parent = $group->fresh() ?? $group;
        $thread = in_array($fresh->status, [TaskStatus::Running, TaskStatus::Reviewing], true)
            ? $this->actingThread($parent, $fresh) : null;

        return json_encode([
            'status' => $fresh->status->value,
            'instance_id' => $parent->taskable_id,
            'thread' => $thread?->only(['id', 'driver', 'runtime_key', 'external_id']),
            'checks' => $fresh->checks()->where('status', TaskCheckStatus::Running->value)->orderBy('id')
                ->get(['id', 'pid', 'process_started', 'head_before', 'tree_before'])->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Refuses a subtask whose running check has no recorded process identity: its start may have been interrupted. A check runs as
     * a `tasks.check` Operation and records no process, so a running check always refuses. Its acting thread has no interrupt here.
     * A thread of a copy turn (driver `t3`) runs its turn as a `tasks.copy_turn` Operation, so the caller must first end the group's open Operations ({@see TaskOperations::cancelOpen()}),
     * as task:complete does; the copy's destroy ends a T3 run that outlived its Operation. A thread of the removed Pi task loop
     * (driver `pi`) ran on a task VM, which is gone with its Pi server, so nothing of it runs.
     */
    public function execute(Task $group, Task $task): void
    {
        if (! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
            return;
        }

        foreach ($task->checks()->where('status', TaskCheckStatus::Running->value)->orderBy('id')->get() as $check) {
            if ($check->pid < 1 || trim($check->process_started) === '') {
                throw $this->stopFailed('The running check has no recorded process identity; its start may have been interrupted.');
            }
        }
    }

    private function actingThread(Task $group, Task $task): ?AgentThread
    {
        if ($task->status === TaskStatus::Running) {
            return $task->implementerThread ?? AgentThread::query()
                ->where('task_id', $task->id)->where('role', 'implementer')->first();
        }

        $reviewers = AgentThread::query()->where('task_group_id', $group->id)->where('task_id', $task->id)
            ->where('role', 'reviewer');

        return (clone $reviewers)->whereKey($group->reviewer_agent_thread_id)->first() ?? $reviewers->latest('id')->first();
    }

    private function stopFailed(string $reason): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_interrupt_failed',
            message: __('The subtask remains running because its implementer or check could not be stopped: :reason', ['reason' => $reason]),
            status: 502,
        );
    }
}
