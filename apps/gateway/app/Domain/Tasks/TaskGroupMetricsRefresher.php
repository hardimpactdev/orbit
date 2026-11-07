<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;

/**
 * Fills settle metrics from recorded thread observations and the shared checkout.
 * Task operations record thread usage; reading the board never contacts Pi.
 */
final readonly class TaskGroupMetricsRefresher
{
    public function __construct(
        private TaskWorkspaceDiffReader $diff,
    ) {}

    public function refresh(Task $group): Task
    {
        $group->loadMissing(['project', 'tasks', 'taskable']);

        if (! $group->groupStatus()->isActive()) {
            return $group;
        }

        $reviewerTokens = 0;
        $hasReviewerTokens = false;
        foreach (AgentThread::query()->where('task_group_id', $group->id)->where('role', TaskThreadRole::Reviewer->value)->orderBy('id')->get() as $reviewer) {
            if (str_starts_with($reviewer->external_id, AgentThread::PendingPrefix)) {
                continue;
            }
            if ($reviewer->tokens !== null) {
                $hasReviewerTokens = true;
                $reviewerTokens += max(0, $reviewer->tokens);
            }
        }
        $taskTokens = 0;
        $hasTaskTokens = false;

        foreach ($group->tasks as $task) {
            $this->refreshTask($task);

            if ($task->tokens !== null) {
                $hasTaskTokens = true;
                $taskTokens += max(0, $task->tokens);
            }
        }

        if ($hasTaskTokens || $hasReviewerTokens) {
            $group->tokens = $taskTokens + $reviewerTokens;
        }

        $instance = $group->taskable;
        $base = $group->project->default_branch;

        if ($instance instanceof Instance && is_string($base) && $base !== '') {
            $changes = $this->diff->lineChanges($instance, $base);
            if ($changes !== null) {
                $group->lines_added = $changes['additions'];
                $group->lines_deleted = $changes['deletions'];
                $group->line_diff = $changes['additions'] + $changes['deletions'];
            }
        }

        $started = $group->started_at;

        if ($started !== null) {
            $group->duration_ms = max(0, (int) now()->diffInMilliseconds($started, true));
        }

        if ($group->isDirty()) {
            $group->save();
        }

        return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
    }

    private function refreshTask(Task $task): void
    {
        $thread = $task->implementerThread;
        if ($thread !== null && ! str_starts_with($thread->external_id, AgentThread::PendingPrefix)) {
            if ($thread->tokens !== null) {
                $task->tokens = $thread->tokens;
            }
            if ($thread->lines_added !== null && $thread->lines_deleted !== null) {
                $task->lines_added = $thread->lines_added;
                $task->lines_deleted = $thread->lines_deleted;
                $task->line_diff = $thread->lines_added + $thread->lines_deleted;
            }
        }

        $started = $task->started_at;

        if ($started !== null) {
            $ended = $task->settled_at ?? now();
            $task->duration_ms = max(0, (int) $ended->diffInMilliseconds($started, true));
        }

        if ($task->isDirty()) {
            $task->save();
        }
    }
}
