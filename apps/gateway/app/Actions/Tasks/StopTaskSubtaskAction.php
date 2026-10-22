<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;

/** Stops remote work before cancellation. Call outside the cancellation transaction. */
final readonly class StopTaskSubtaskAction
{
    public function __construct(
        private AgentDriverRegistry $drivers,
        private TaskCheckRunner $checks,
    ) {}

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

    public function execute(Task $group, Task $task): void
    {
        if (! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
            return;
        }

        $group = $group->fresh() ?? $group;
        $thread = $this->actingThread($group, $task);
        if ($thread instanceof AgentThread) {
            try {
                $this->drivers->get($thread->driver)->interrupt($thread);
            } catch (AgentDriverException $exception) {
                throw $this->stopFailed($exception->getMessage());
            }
        }

        $instance = $group->fresh()?->taskable;
        foreach ($task->checks()->where('status', TaskCheckStatus::Running->value)->orderBy('id')->get() as $check) {
            if ($check->pid < 1 || trim($check->process_started) === '') {
                throw $this->stopFailed('The running check has no recorded process identity; its start may have been interrupted.');
            }
            if (! $instance instanceof Instance) {
                return;
            }
            try {
                $this->checks->cancel($instance, $check->process());
                app(TaskWorkspaceLease::class)->invalidateCheck($instance, $check->process());
            } catch (TaskCheckException $exception) {
                throw $this->stopFailed($exception->getMessage());
            }
        }
    }

    /** External board status does not identify the actor that owns the workspace. */
    public function executeExternal(Task $group, Task $task): void
    {
        $holder = $this->externalHolder($group, $task);
        if (! $holder instanceof TaskWorkspaceLeaseRecord) {
            return;
        }
        $instance = Instance::query()->find($holder->instance_id);
        if (! $instance instanceof Instance) {
            throw $this->stopFailed('The held workspace is unavailable.');
        }
        try {
            if ($holder->check_id !== null || $holder->pid !== null) {
                $check = TaskCheck::query()->where('task_id', $task->id)->find($holder->check_id);
                if (! $check instanceof TaskCheck || $holder->pid === null || $holder->pid < 1 || trim($holder->process_started ?? '') === '') {
                    throw new TaskCheckException('The held check has no confirmed process identity.');
                }
                $process = new TaskCheckProcess($holder->pid, $holder->process_started ?? '', $holder->process_head ?? $holder->head_before ?? '', $holder->process_tree ?? $holder->tree_before ?? '', is_string($holder->getAttribute('check_run_id')) ? $holder->getAttribute('check_run_id') : null);
                $this->checks->cancel($instance, $process);
                $leases = app(TaskWorkspaceLease::class);
                $leases->invalidateCheck($instance, $process);
                $reading = $leases->readCheck($instance, $process);
                if ($reading->state !== 'finished') {
                    throw new TaskCheckException('The held check could not be confirmed stopped.');
                }
                $leases->finishedCheck($check, $reading);
                if ($holder->fresh()?->getAttribute('completion_pending')) {
                    $leases->reconcile($group->fresh() ?? $group);
                }
            } else {
                $thread = AgentThread::query()->where('task_group_id', $group->id)->find($holder->thread_id);
                if (! $thread instanceof AgentThread) {
                    throw $this->stopFailed('The held workspace actor has no recorded thread or check.');
                }
                $this->drivers->get($thread->driver)->interrupt($thread);
                app(TaskWorkspaceLease::class)->reconcile($group->fresh() ?? $group);
            }
        } catch (TaskCheckException $exception) {
            throw new ResourceOperationException('tasks.check_unreachable', 'The subtask remains uncancelled because its check could not be confirmed stopped: '.$exception->getMessage(), 502);
        } catch (AgentDriverException $exception) {
            throw $this->stopFailed($exception->getMessage());
        }
        $this->assertExternalStopped($group, $task);
    }

    /** Recheck under the workspace admission lock immediately before recording cancellation. */
    public function assertExternalStopped(Task $group, Task $task): void
    {
        if ($this->externalHolder($group, $task) instanceof TaskWorkspaceLeaseRecord) {
            throw $this->stopFailed('A workspace actor still holds the subtask lease.');
        }
    }

    private function externalHolder(Task $group, Task $task): ?TaskWorkspaceLeaseRecord
    {
        return TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('status', 'held')
            ->where(function ($query) use ($group, $task): void {
                $query->where('task_id', $task->id)
                    ->orWhereIn('thread_id', AgentThread::query()->where('task_group_id', $group->id)->where('task_id', $task->id)->select('id'))
                    ->orWhereIn('check_id', $task->checks()->select('id'));
            })->lockForUpdate()->first();
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
