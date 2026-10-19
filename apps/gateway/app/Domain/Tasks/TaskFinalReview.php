<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\StoredInteger;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ADR 0203: the final review of a review-and-merge task. Orbit appends it when the task holds approved work
 * that no final review approved. It has no implementer. Its approval is the only step that pushes.
 */
final readonly class TaskFinalReview
{
    public const string Title = 'Final review';

    public const string DeliverableId = 'final-review';

    /** The fixup identity of the subtask that addresses a final review's findings. */
    public const string FixupProblem = 'final-review';

    public const string FixupTitle = 'Address final review';

    /** A delta-only final review of an overlapping mechanical main merge; not an agent fixup. */
    public const string BaseMergeReview = 'base-merge-review';

    /** Final-review fixups in one window before the task asks for assistance. */
    public const int FixupLimit = 2;

    public const int DeltaReviewLimit = 3;

    /** Assistance set when someone other than Orbit pushed to an Orbit task branch. */
    public const string UnreviewedHeadPrefix = 'The pull request head was not reviewed by Orbit: ';

    /** Assistance set when final reviews keep requesting changes. */
    public const string FixupCapPrefix = 'The final review keeps requesting changes: ';

    /** The brief of a final review fixup holds at most this many characters of findings. */
    private const int FindingsLimit = 7000;

    public static function isUnreviewedHeadReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::UnreviewedHeadPrefix);
    }

    /** Requests that wait for an operator subtask. Appending one resumes the task and clears the request. */
    public static function isResumableReason(?string $reason): bool
    {
        return self::isCapReason($reason) || self::isUnreviewedHeadReason($reason);
    }

    /** Whether the reason is the final-review cap. An appended operator subtask resumes past it. */
    public static function isCapReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::FixupCapPrefix);
    }

    public static function brief(Task $group): string
    {
        $previous = $group->tasks()->reorder('position', 'desc')->first();
        if ($previous instanceof Task && $previous->fixup_problem === self::FixupProblem) {
            return 'Re-review only the change since the last final review. Confirm that its findings are resolved and look for new blocking findings in that change. '
                .'Orbit applies unresolved findings in a new subtask.';
        }
        $default = $group->project->default_branch ?? 'the default branch';
        if ($group->reviewsIncomingPullRequest()) {
            return 'Review pull request '.$group->pr_url.' as a whole before Orbit approves it on GitHub and merges it. '
                .'The diff runs from its merge base with origin/'.$default.' to the pull request head. '
                .'Approve only when the complete change is correct, tested, documented, and ready to merge as it is. '
                .'Block only on findings that meet the blocking rule and are new since the subtask approvals. Do not reopen hardening that the subtask reviews already covered. '
                .'Orbit applies the findings in a new subtask.';
        }

        return 'Review the whole task branch before Orbit pushes it'.(is_string($group->pr_url) && $group->pr_url !== '' ? ' to '.$group->pr_url : ' and opens the pull request').'. '
            .'The diff runs from its merge base with origin/'.$default.' to HEAD, and covers every approved subtask. '
            .'Approve only when the complete change meets the task brief, is tested and documented, and is ready to merge. '
            .'Block only on findings that meet the blocking rule and are new since the subtask approvals. Do not reopen hardening that the subtask reviews already covered. '
            .'Orbit applies the findings in a new subtask.';
    }

    /** @return list<array<string, string>> */
    public static function deliverables(): array
    {
        return [[
            'id' => self::DeliverableId,
            'type' => 'review',
            'description' => 'The whole change meets the brief and is ready to merge as it is.',
        ]];
    }

    public static function fixupBrief(Task $finalReview, TaskComment $receipt): string
    {
        $findings = trim($receipt->body);
        if (mb_strlen($findings) > self::FindingsLimit) {
            $findings = mb_substr($findings, 0, self::FindingsLimit)."\n\n[Cut. The full findings are comment #".$receipt->id.' on subtask #'.$finalReview->id.'.]';
        }

        return 'Fix each blocking finding of final review subtask #'.$finalReview->id.' at its root cause, or explain in your handoff why it does not apply within the task brief. List follow-ups in the handoff instead of implementing them. Do not rebase and do not force-push.'
            ."\n\nFindings:\n\n".$findings;
    }

    /** @return list<array<string, string>> */
    public static function fixupDeliverables(): array
    {
        return [['id' => 'final-review-findings', 'type' => 'review', 'description' => 'Each blocking finding of the final review is addressed or explicitly resolved within the task brief. Follow-ups are listed in the handoff.']];
    }

    /**
     * The latest reviewed head that publication has not pushed. A merge needs no preservation push.
     * Incoming author heads already live on origin. With per-subtask publication, a handled approval is pushed.
     * Reconciles a matching queued push receipt before checking VM availability: origin already holds that head.
     */
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

    /** Records a confirmed removal push in the publication state that selects the head. */
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

    public static function isReviewed(Task $group, string $sha): bool
    {
        return TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $sha)->exists();
    }

    /**
     * Whether the task holds approved work that no final review approved. A final review approves the whole
     * workspace HEAD, which holds every earlier approval even when a fast-forward moved HEAD past it.
     */
    public static function hasUnreviewedWork(Task $group): bool
    {
        $approval = TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->whereHas('task', static fn ($query) => $query->withoutFinalReviews())
            ->latest('id')
            ->first(['id', 'commit_sha']);
        if (! $approval instanceof TaskComment || ! is_string($approval->commit_sha) || $approval->commit_sha === '') {
            return false;
        }
        if (self::isReviewed($group, $approval->commit_sha)) {
            return false;
        }

        return ! TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->where('id', '>', $approval->id)
            ->whereHas('task', static fn ($query) => $query->where('type', TaskType::FinalReview->value))
            ->whereIn('commit_sha', TaskReviewedCommit::query()->where('task_id', $group->id)->select('sha'))
            ->exists();
    }

    /**
     * Whether Orbit must append a final review: the task reviews before it pushes, no subtask is open, and its
     * latest approved work has no final review.
     *
     * @param  Collection<int, Task>  $tasks
     */
    public static function due(Task $group, Collection $tasks): bool
    {
        return $group->reviewsBeforePush()
            && ! $tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))
            && self::hasUnreviewedWork($group);
    }

    /**
     * Final-review fixups after the latest completed operator subtask. Final reviews themselves are not operator work.
     *
     * @param  Collection<int, Task>  $tasks
     */
    public static function fixupsInWindow(Collection $tasks): int
    {
        $ordered = $tasks->sortBy(static fn (Task $task): array => [$task->position, $task->id])->values();
        $boundary = $ordered
            ->filter(static fn (Task $task): bool => $task->fixup_problem === null && ! $task->isFinalReview() && $task->status === TaskStatus::Completed)
            ->max('position');

        return $ordered->filter(static fn (Task $task): bool => $task->fixup_problem === self::FixupProblem
            && ($boundary === null || $task->position > $boundary))->count();
    }

    /** @param  Collection<int, Task>  $tasks */
    public static function deltaReviewsInWindow(Collection $tasks): int
    {
        $boundary = $tasks->filter(static fn (Task $task): bool => $task->fixup_problem === null
            && ! $task->isFinalReview() && $task->status === TaskStatus::Completed)->max('position');

        return $tasks->filter(static fn (Task $task): bool => $task->fixup_problem === self::BaseMergeReview
            && ($boundary === null || $task->position > $boundary))->count();
    }

    /** @param  Collection<int, Task>  $tasks */
    public static function nextPosition(Collection $tasks): int
    {
        return StoredInteger::fromOrZero($tasks->max('position')) + 1;
    }

    /** @param  array<string, mixed>  $properties */
    public static function log(Task $task, string $description, array $properties = []): void
    {
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => $description, 'subject_type' => $task::class,
            'subject_id' => $task->id, 'properties' => $properties,
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:tick', 'status' => 'completed',
        ]);
    }
}
