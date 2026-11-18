<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckedCandidate;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequest;
use App\Domain\Tasks\TaskReviewMode;
use App\Models\Operation;
use App\Models\Task;
use Closure;
use Illuminate\Support\Str;

/**
 * One tick of the gate check of stage 4 of the command development pipeline, run every minute by the Gateway
 * scheduler. It only reads GitHub and admits. For each open external group of a review-and-merge Project that has a
 * published pull request it reads the pull request. A draft with a passed handoff check on its head gets the ready step in
 * the mode of the group; a draft without one stays a draft. A pull request out of draft gets the merge step in manual mode.
 * Admission makes the other reads: the CI result of the head, and for the merge the reviewer's approval of that exact head.
 * A pending check, a missing approval, a busy group or a failed read is a quiet skip; the next tick reads again.
 *
 * The review rounds of automatic mode ran on task VMs, with the build and the corrections of a prep group; they are removed
 * (docs/reference/old-paths.md). A group in automatic mode switches to manual mode, and the ready step asks the reviewer.
 *
 * Each admission is keyed by group, step and head, so a repeat tick on the same head replays the recorded step and
 * starts nothing. A push moves the head and starts the check again.
 *
 * @phpstan-type Result array{group: int, pull_request: int|null, step: string|null, result: string, code: string|null, operation: int|null}
 */
final readonly class RunTaskPullRequestGateAction
{
    public const string Caller = 'gateway:pr-gate';

    public function __construct(private StartTaskPullRequestStepAction $steps, private TaskCheckedCandidate $candidates,
        private RepositoryPullRequestAccess $access, private GitHubApi $github, private OperationIdempotency $idempotency) {}

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
        // A task copy's pull request gets no ready step and no merge (docs/reference/tasks.md#no-merge).
        if (TaskPullRequest::copy($group)) {
            return $this->result($group, $number, null, 'skipped', TaskPullRequest::Code);
        }
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
            if (! $this->checked($group, $head)) {
                return $this->result($group, $number, 'ready', 'skipped', 'tasks_pr.no_checked_head');
            }

            return $this->admit($group, $number, 'ready', OperationKinds::TasksPrReady, $head, fn () => $this->steps->ready($group, $head, $group->review_mode->value, self::Caller, $request));
        }
        if ($group->review_mode === TaskReviewMode::Automatic) {
            return $this->fallBack($group, $number, $head, $request);
        }

        return $this->merge($group, $number, $head, $request);
    }

    /** A draft leaves draft only when a passed handoff check of the group, with its required CI, is on exactly this head. */
    private function checked(Task $group, string $head): bool
    {
        return $this->candidates->onHead($group, $group->tasks()->pluck('id')->all(), $head);
    }

    /**
     * The group goes back to manual mode: the ready step runs again, and in manual mode it asks the reviewer. The mode changes only
     * with an admitted ready step, so a refused one (a red check, a busy group) is tried again by the next tick instead of a merge
     * that waits for a review nobody asked for.
     *
     * @return Result
     */
    private function fallBack(Task $group, int $number, string $head, string $request): array
    {
        return $this->admit($group, $number, 'ready-manual', OperationKinds::TasksPrReady, $head, function () use ($group, $head, $request): Operation {
            $operation = $this->steps->ready($group, $head, TaskReviewMode::Manual->value, self::Caller, $request);
            $group->update(['review_mode' => TaskReviewMode::Manual]);

            return $operation;
        });
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
