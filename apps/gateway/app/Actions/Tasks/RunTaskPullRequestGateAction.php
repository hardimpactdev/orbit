<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPrepFixData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewOverflowException;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\PullRequestGate;
use App\Domain\Tasks\TaskBuildIntent;
use App\Domain\Tasks\TaskCheckedCandidate;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrepOwnerReview;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskReviewRound;
use App\Models\TaskVm;
use Closure;
use Illuminate\Support\Str;

/**
 * One tick of the gate check of stage 4 of the command development pipeline, run every minute by the Gateway
 * scheduler. It only reads GitHub and admits. For each open external group of a review-and-merge Project that has a
 * published pull request it reads the pull request. A draft with a passed handoff check on its head gets the ready step in
 * the mode of the group; a draft without one stays a draft, except the spec of a prep group whose build has not started, which
 * gets the build step once the owner approved its head. Until then the owner's request for changes on that head gets the corrections
 * (`tasks:prep-fix`, keyed by group and review). A pull request out of draft gets the merge step in manual mode; in
 * automatic mode it gets one review round per head, one Swamp run with the reviewer, the comment, and for findings the fix and the push, and the merge step once the round on that exact head passed. Admission makes the other reads: the CI result of the head,
 * and for the manual merge the reviewer's approval of that exact head. A pending check, a missing approval, a running
 * round, a busy group or a failed read is a quiet skip; the next tick reads again.
 *
 * Each admission is keyed by group, step and head, so a repeat tick on the same head replays the recorded step and
 * starts nothing. A push moves the head and starts the check again. After round 3 with findings, or when a round
 * fails, the group switches to manual mode and the ready step asks the reviewer.
 *
 * @phpstan-type Result array{group: int, pull_request: int|null, step: string|null, result: string, code: string|null, operation: int|null}
 */
final readonly class RunTaskPullRequestGateAction
{
    public const string Caller = 'gateway:pr-gate';

    public function __construct(private StartTaskPullRequestStepAction $steps, private StartTaskReviewRoundAction $rounds, private StartTaskPrepFixAction $prepFixes, private TaskCheckedCandidate $candidates,
        private RepositoryPullRequestAccess $access, private GitHubApi $github, private OperationIdempotency $idempotency, private StartTaskBuildAction $builds) {}

    /** @return list<Result> */
    public function execute(): array
    {
        $results = [];
        foreach ($this->candidates() as $group) {
            $results[] = $this->check($group);
        }

        return $results;
    }

    /** @return list<Task> */
    private function candidates(): array
    {
        $groups = Task::topLevel()->with('project')
            ->where('orchestration', TaskOrchestration::External->value)
            ->whereNotNull('watched_pr_number')->whereNull('watched_pr_completion')
            ->orderBy('id')->get()
            ->filter(static fn (Task $group): bool => ! in_array($group->status, TaskGroupStatus::endedStatuses(), true) && $group->project->reviewsAndMerges());

        return array_values($groups->all());
    }

    /** @return Result */
    private function check(Task $group): array
    {
        $number = $group->watched_pr_number;
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        if (! is_int($number) || ! $repository instanceof GitHubRepository) {
            return $this->result($group, $number, null, 'skipped', 'tasks_pr.no_pull_request');
        }
        try {
            $pull = $this->github->pullRequest($this->access->reviewsToken($repository), $repository, $number);
        } catch (GitHubApiException $exception) {
            report($exception);

            return $this->result($group, $number, null, 'skipped', 'tasks_pr.github_unreadable');
        }
        if ($pull->state !== GitHubPullRequestState::Open || $pull->headSha === null) {
            return $this->result($group, $number, null, 'skipped', 'tasks_pr.no_pull_request');
        }
        $head = $pull->headSha;
        $request = (string) Str::uuid();
        if ($pull->draft) {
            // The spec of a prep group whose build has not started: the approval of its head starts the build, and nothing else looks at this draft.
            if (TaskBuildIntent::waiting($group)) {
                $build = $this->admit($group, $number, 'build', OperationKinds::TasksBuild, $head, fn () => $this->builds->execute($group, $head, self::Caller, $request));

                // Without the approval of this head, the owner may have asked for corrections instead.
                return $build['code'] === 'tasks_pr.approval_missing' ? ($this->corrections($group, $number, $repository, $head, $request) ?? $build) : $build;
            }
            if (! $this->checked($group, $head)) {
                return $this->result($group, $number, 'ready', 'skipped', 'tasks_pr.no_checked_head');
            }

            return $this->admit($group, $number, 'ready', OperationKinds::TasksPrReady, $head, fn () => $this->steps->ready($group, $head, $group->review_mode->value, self::Caller, $request));
        }
        if ($group->review_mode === TaskReviewMode::Manual) {
            return $this->merge($group, $number, $head, $request);
        }

        return $this->automatic($group, $number, $head, $request);
    }

    /**
     * The spec of a prep group waits for its owner in a draft without a passed handoff check. His latest decision, a request for
     * changes on exactly this head, is the request for corrections, and his review text and inline comments are the corrections.
     * Null when the group is no parked prep group or the owner asked for nothing, so the draft stays as it is and no corrections are admitted.
     * The key holds the review, so a run that failed is not started again by the next tick: the replay reports it, and a new review starts a new run.
     *
     * @return Result|null
     */
    private function corrections(Task $group, int $number, GitHubRepository $repository, string $head, string $request): ?array
    {
        if (TaskVm::forGroup($group)?->state !== TaskVmState::Parked
            || ! Operation::query()->where('kind', OperationKinds::TasksPrep)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)->where('status', OperationStatus::Succeeded)->exists()) {
            return null;
        }
        try {
            $reviewer = TaskPullRequestEvidence::reviewer();
            $token = $this->access->reviewsToken($repository);
            $review = PullRequestGate::changesRequested($this->github->reviews($token, $repository, $number), $head, $reviewer);
            $comments = $review === null ? [] : $this->github->reviewComments($token, $repository, $number, $review->id);
        } catch (GitHubApiException|GitHubReviewOverflowException $exception) {
            report($exception);

            return $this->result($group, $number, 'prep-fix', 'skipped', 'tasks_pr.github_unreadable');
        }
        if ($review === null) {
            return null;
        }
        $corrections = TaskPrepOwnerReview::corrections($review, $comments, $reviewer);
        $result = $this->admit($group, $number, 'prep-fix', OperationKinds::TasksPrepFix, 'review-'.$review->id, fn () => $this->prepFixes->execute($group, new StartTaskPrepFixData($corrections), self::Caller, $request));
        if ($result['result'] === 'replayed' && Operation::query()->find($result['operation'])?->status === OperationStatus::Failed) {
            return $this->result($group, $number, 'prep-fix', 'skipped', 'tasks_pr.prep_fix_failed', $result['operation']);
        }

        return $result;
    }

    /** A draft leaves draft only when a passed handoff check of the group, with its required CI, is on exactly this head. */
    private function checked(Task $group, string $head): bool
    {
        return $this->candidates->onHead($group, $group->tasks()->pluck('id')->all(), $head);
    }

    /**
     * One admission for each head: the round runs as one Swamp run, with the comment and the fix inside it. The gate waits for the
     * verdict, admits the merge step after a pass, and sends the group back to manual mode after findings that no push followed.
     *
     * @return Result
     */
    private function automatic(Task $group, int $number, string $head, string $request): array
    {
        $round = TaskReviewRound::query()->where('task_group_id', $group->id)->where('head_sha', $head)->first();
        if ($round === null) {
            return TaskReviewRound::query()->where('task_group_id', $group->id)->count() >= TaskReviewRound::Limit
                ? $this->fallBack($group, $number, $head, $request)
                : $this->admit($group, $number, 'review', OperationKinds::TasksReviewRound, $head, fn () => $this->rounds->execute($group, $head, self::Caller, $request));
        }

        return match ($round->verdict) {
            null => $this->result($group, $number, 'review', 'skipped', 'tasks_pr.round_running', $round->operation_id),
            TaskReviewRoundVerdict::Pass => $this->merge($group, $number, $head, $request),
            // The push moves the head of the pull request; a tick that still reads the old head waits for the new one.
            TaskReviewRoundVerdict::Findings => $round->pushed_commit !== null
                ? $this->result($group, $number, 'review', 'skipped', 'tasks_pr.fix_pushed', $round->operation_id)
                : $this->fallBack($group, $number, $head, $request),
            TaskReviewRoundVerdict::Failed => $this->fallBack($group, $number, $head, $request),
        };
    }

    /**
     * The group goes back to manual mode: the ready step runs again, and in manual mode it asks the reviewer.
     *
     * @return Result
     */
    private function fallBack(Task $group, int $number, string $head, string $request): array
    {
        $group->update(['review_mode' => TaskReviewMode::Manual]);

        return $this->admit($group, $number, 'ready-manual', OperationKinds::TasksPrReady, $head, fn () => $this->steps->ready($group, $head, TaskReviewMode::Manual->value, self::Caller, $request));
    }

    /** @return Result */
    private function merge(Task $group, int $number, string $head, string $request): array
    {
        return $this->admit($group, $number, 'merge', OperationKinds::TasksPrMerge, $head, fn () => $this->steps->merge($group, $head, self::Caller, $request));
    }

    /**
     * @param  Closure(): Operation  $start
     * @return Result
     */
    private function admit(Task $group, int $number, string $step, string $kind, string $subject, Closure $start): array
    {
        try {
            $operation = $this->idempotency->under($kind, "pr-gate:{$step}:{$group->id}:{$subject}", hash('sha256', "{$kind}|{$group->id}|{$subject}"), $start);
        } catch (ResourceOperationException $exception) {
            return $this->result($group, $number, $step, 'skipped', $exception->errorCode);
        }

        return $this->result($group, $number, $step, $operation->wasRecentlyCreated ? 'admitted' : 'replayed', null, $operation->id);
    }

    /** @return Result */
    private function result(Task $group, mixed $number, ?string $step, string $result, ?string $code, ?int $operation = null): array
    {
        return ['group' => $group->id, 'pull_request' => is_int($number) ? $number : null, 'step' => $step, 'result' => $result, 'code' => $code, 'operation' => $operation];
    }
}
