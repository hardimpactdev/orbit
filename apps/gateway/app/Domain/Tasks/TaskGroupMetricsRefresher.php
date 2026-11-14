<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/** Computes display metrics from Gateway records without changing them. */
final readonly class TaskGroupMetricsRefresher
{
    public function refresh(Task $group): Task
    {
        $group->loadMissing(['project', 'tasks', 'taskable']);
        $this->refreshMany(new Collection([$group]));

        return $group;
    }

    /**
     * The metrics of many groups in one query, whatever their number. Each group's subtasks must be loaded.
     *
     * @param  Collection<int, Task>  $groups
     */
    public function refreshMany(Collection $groups): void
    {
        // A group's tokens are the sum of its started threads' usage; a group without a thread that reports usage keeps its stored total.
        $tokens = AgentThread::query()->whereIn('task_group_id', $groups->modelKeys())->started()->groupBy('task_group_id')
            ->selectRaw('task_group_id, sum(case when tokens < 0 then 0 else tokens end) as tokens')->toBase()->pluck('tokens', 'task_group_id');
        foreach ($groups as $group) {
            $total = $tokens[$group->id] ?? null;
            if (is_numeric($total)) {
                $group->tokens = (int) $total;
            }
            foreach ($group->tasks as $task) {
                $this->duration($task, $task->settled_at);
            }
            $this->duration($group, $group->ended_at ?? $group->settled_at);
        }
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
