<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\TaskVms\TaskVmPublishState;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;

/** Preserves recorded approved work during explicit workspace removal. Starts no review or fixup. */
final readonly class TaskFinalReview
{
    public const string FixupProblem = 'final-review';

    public static function reconcileRemovalPush(Task $group): ?string
    {
        $commit = self::unpublishedRemovalCommit($group);
        if ($commit !== null && TaskVm::query()->where('group_id', $group->id)
            ->where('publish_state', TaskVmPublishState::Pushed)->where('publish_commit', $commit)
            ->where('publish_branch', TaskRemoteBranch::for($group))->exists()) {
            self::recordRemovalPush($group, $commit);

            return null;
        }

        return $commit;
    }

    private static function unpublishedRemovalCommit(Task $group): ?string
    {
        if ($group->watched_pr_completion === 'merged' || $group->merged_sha !== null) {
            return null;
        }
        if ($group->reviewsBeforePush()) {
            $reviewed = TaskReviewedCommit::query()->where('task_id', $group->id)->latest('id')->first();

            return $reviewed instanceof TaskReviewedCommit && $reviewed->pushed_at === null
                && $reviewed->source !== TaskReviewedCommitSource::PullRequestReview ? $reviewed->sha : null;
        }
        $approval = TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->latest('id')->first();
        if (! $approval instanceof TaskComment || ! is_string($approval->commit_sha) || $approval->commit_sha === ''
            || ($approval->task->review_handled_comment_id ?? 0) >= $approval->id) {
            return null;
        }

        return $approval->commit_sha;
    }

    public static function recordRemovalPush(Task $group, string $sha): void
    {
        if ($group->reviewsBeforePush()) {
            TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $sha)->whereNull('pushed_at')->update(['pushed_at' => now()]);
        } else {
            $approval = TaskComment::query()->where('task_group_id', $group->id)->where('type', TaskCommentType::Approved)
                ->where('commit_sha', $sha)->latest('id')->firstOrFail();
            Task::query()->whereKey($approval->task_id)
                ->where(static fn ($query) => $query->whereNull('review_handled_comment_id')->orWhere('review_handled_comment_id', '<', $approval->id))
                ->update(['review_handled_comment_id' => $approval->id]);
        }
        Log::info('Pushed approved work before removing a task workspace.', [
            'task_group_id' => $group->id, 'commit_sha' => $sha, 'branch' => TaskRemoteBranch::for($group),
        ]);
    }
}
