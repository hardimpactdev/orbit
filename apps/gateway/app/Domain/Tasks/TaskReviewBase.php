<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

/**
 * The commit a subtask review and its base run both diff from.
 * A recorded start commit wins. Continuations inherit their source subtask's base; other tasks use the previous approved commit, then the workspace starting commit.
 */
final class TaskReviewBase
{
    public static function commit(Task $task): string
    {
        $recorded = $task->subtask_start_commit;
        if (self::isCommit($recorded)) {
            return $recorded;
        }
        if ($task->continuation_of_task_id !== null) {
            $source = Task::query()->find($task->continuation_of_task_id);
            if ($source instanceof Task) {
                $sourceStart = self::commit($source);
                if ($sourceStart !== '') {
                    return $sourceStart;
                }
            }
        }
        $task->loadMissing('parent.taskable');
        $group = $task->parent;
        $previous = self::previousApprovedCommit($group, $task);
        if ($previous !== null) {
            return $previous;
        }
        $instance = $group->taskable;
        $starting = $instance instanceof Instance ? $instance->starting_commit : null;

        return self::isCommit($starting) ? $starting : '';
    }

    /** A passed handoff tree includes uncommitted work that HEAD alone would miss. */
    public static function testBase(Task $task): string
    {
        $check = $task->checks()->where('kind', TaskCheckKind::Handoff)
            ->where('status', TaskCheckStatus::Passed)->latest('id')->first();
        $tree = $check instanceof TaskCheck ? ($check->tree_after ?? $check->tree_before) : null;

        return self::isTree($tree) ? $tree : self::commit($task);
    }

    /** The last review whose findings this turn addresses, including a final-review fixup. */
    public static function previousReview(Task $task): ?Task
    {
        if ($task->fixup_problem !== TaskFinalReview::BaseMergeReview && self::isTree($task->review_workspace_tree)) {
            return $task;
        }
        if (! $task->isFinalReview()) {
            return null;
        }
        if ($task->fixup_problem === TaskFinalReview::BaseMergeReview) {
            $review = $task->parent->tasks()->where('position', '<', $task->position)
                ->where('type', TaskType::FinalReview)
                ->whereHas('comments', static fn ($query) => $query->where('type', TaskCommentType::Approved))
                ->whereNotNull('review_workspace_tree')->reorder('position', 'desc')->first();

            return $review instanceof Task && self::isTree($review->review_workspace_tree) ? $review : null;
        }
        $previous = $task->parent->tasks()->where('position', '<', $task->position)->reorder('position', 'desc')->first();
        if (! $previous instanceof Task || $previous->fixup_problem !== TaskFinalReview::FixupProblem) {
            return null;
        }
        $review = $task->parent->tasks()->where('position', '<', $previous->position)
            ->where('type', TaskType::FinalReview)->reorder('position', 'desc')->first();

        return $review instanceof Task && self::isTree($review->review_workspace_tree) ? $review : null;
    }

    public static function reviewBase(Task $task): string
    {
        return self::previousReview($task)->review_workspace_tree ?? self::commit($task);
    }

    public static function findings(Task $task): string
    {
        $previous = self::previousReview($task);
        if ($previous === null) {
            return '';
        }
        $body = $previous->comments()->where('type', TaskCommentType::ChangesRequested)->latest('id')->value('body');

        return is_string($body) ? $body : '';
    }

    /** @phpstan-assert-if-true string $value */
    private static function isTree(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $value) === 1;
    }

    /** The workspace commit recorded when the group workspace was created, or empty when it is missing. */
    public static function groupStartCommit(Task $group): string
    {
        $group->loadMissing('taskable');
        $instance = $group->taskable;
        $starting = $instance instanceof Instance ? $instance->starting_commit : null;

        return is_string($starting) ? $starting : '';
    }

    private static function previousApprovedCommit(Task $group, Task $task): ?string
    {
        $earlier = Task::query()
            ->where('parent_id', $group->id)
            ->where(function ($query) use ($task): void {
                $query->where('position', '<', $task->position)
                    ->orWhere(function ($query) use ($task): void {
                        $query->where('position', $task->position)->where('id', '<', $task->id);
                    });
            })
            ->pluck('id');
        if ($earlier->isEmpty()) {
            return null;
        }
        $commit = TaskComment::query()
            ->whereIn('task_id', $earlier)
            ->where('type', TaskCommentType::Approved->value)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');

        return self::isCommit($commit) ? $commit : null;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isCommit(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{7,64}\z/i', $value) === 1;
    }
}
