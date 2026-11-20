<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\GatewayRelease;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskHandoff;
use App\Models\TaskQuestion;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Records the [task insights](/reference/tasks#task-insights) row of the group a model save changes: a group ending, merging or
 * deploying, a subtask, a thread's tokens, a review verdict, a handoff check, a handoff, a question, or a verified release. It runs after
 * the commit and never fails the write that fired it: a failed record is logged, and the next totals refresh records it again.
 */
final readonly class TaskInsightObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private TaskInsightRecorder $recorder) {}

    public function saved(Model $model): void
    {
        $this->guarded(fn () => $this->changed($model));
    }

    public function deleted(Model $model): void
    {
        $this->guarded(fn () => $model instanceof Task || $model instanceof TaskComment || $model instanceof TaskQuestion || $model instanceof AgentThread
            ? $this->changed($model) : null);
    }

    private function changed(Model $model): void
    {
        $groupId = match (true) {
            $model instanceof Task => $model->parent_id ?? $model->id,
            $model instanceof AgentThread => $model->task_group_id,
            $model instanceof TaskComment => $model->task_group_id,
            $model instanceof TaskQuestion => $model->task_id,
            $model instanceof TaskCheck, $model instanceof TaskHandoff => Task::query()->whereKey($model->task_id)->value('parent_id') ?? $model->task_id,
            default => null,
        };
        if ($model instanceof GatewayRelease) {
            $this->releaseChanged($model);
        } elseif (is_numeric($groupId)) {
            $this->recorder->record((int) $groupId);
        }
    }

    /** A verified release changes the release of every group that started after it began. */
    private function releaseChanged(GatewayRelease $release): void
    {
        if ($release->outcome === 'verified' && $release->sha !== null && $release->created_at !== null) {
            $this->recorder->recordAll(Task::topLevel()->where('started_at', '>=', $release->created_at)
                ->whereIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses())));
        }
    }

    private function guarded(\Closure $record): void
    {
        try {
            $record();
        } catch (\Throwable $exception) {
            Log::warning('task insights: recording a task group failed; the next totals refresh records it', ['exception' => $exception]);
        }
    }
}
