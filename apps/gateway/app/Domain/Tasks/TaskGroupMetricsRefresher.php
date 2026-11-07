<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use Carbon\CarbonInterface;

/** Computes display metrics from Gateway records without changing them. */
final readonly class TaskGroupMetricsRefresher
{
    public function refresh(Task $group): Task
    {
        $group->loadMissing(['project', 'tasks', 'taskable']);
        $tokens = null;
        foreach (AgentThread::query()->where('task_group_id', $group->id)->started()->get() as $thread) {
            if ($thread->tokens !== null) {
                $tokens = ($tokens ?? 0) + max(0, $thread->tokens);
            }
        }
        if ($tokens !== null) {
            $group->tokens = $tokens;
        }
        foreach ($group->tasks as $task) {
            $this->duration($task, $task->settled_at);
        }
        $this->duration($group, $group->ended_at ?? $group->settled_at);

        return $group;
    }

    private function duration(Task $task, ?CarbonInterface $ended): void
    {
        $terminal = $task->isTopLevel()
            ? in_array($task->status, TaskGroupStatus::endedStatuses(), true)
            : in_array($task->status, [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled], true);
        if ($task->started_at !== null && ($ended !== null || ! $terminal)) {
            $task->duration_ms = max(0, (int) ($ended ?? now())->diffInMilliseconds($task->started_at, true));
        }
    }
}
