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
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Models\Task;
use Closure;
use Illuminate\Support\Str;

/**
 * One tick of the gate check of stage 4 of the command development pipeline, run every minute by the Gateway
 * scheduler. It only reads GitHub and admits. For each open external group of a review-and-merge Project that has a
 * published pull request it reads the pull request. A draft gets the ready step, a pull request out of draft gets the
 * merge step. Admission makes the other reads: the CI result of the head, and for the merge the reviewer's approval of
 * that exact head. A pending check, a missing approval, a busy group or a failed read is a quiet skip; the next tick
 * reads again.
 *
 * Each admission is keyed by group, step and head, so a repeat tick on the same head replays the recorded step and
 * starts nothing. A push moves the head and starts the check again. Only manual mode exists.
 *
 * @phpstan-type Result array{group: int, pull_request: int|null, step: string|null, result: string, code: string|null, operation: int|null}
 */
final readonly class RunTaskPullRequestGateAction
{
    public const string Caller = 'gateway:pr-gate';

    public function __construct(private StartTaskPullRequestStepAction $steps, private RepositoryPullRequestAccess $access,
        private GitHubApi $github, private OperationIdempotency $idempotency) {}

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
        [$step, $kind] = $pull->draft ? ['ready', OperationKinds::TasksPrReady] : ['merge', OperationKinds::TasksPrMerge];

        try {
            $operation = $this->keyed($kind, $step, $group, $head, fn () => $pull->draft
                ? $this->steps->ready($group, $head, 'manual', self::Caller, $request)
                : $this->steps->merge($group, $head, self::Caller, $request));
        } catch (ResourceOperationException $exception) {
            return $this->result($group, $number, $step, 'skipped', $exception->errorCode);
        }

        return $this->result($group, $number, $step, $operation->wasRecentlyCreated ? 'admitted' : 'replayed', null, $operation->id);
    }

    /**
     * Runs one admission under the key of the group, step and head, as the HTTP boundary does for a request header.
     *
     * @template T
     *
     * @param  Closure(): T  $admit
     * @return T
     */
    private function keyed(string $kind, string $step, Task $group, string $head, Closure $admit): mixed
    {
        $previous = [$this->idempotency->kind, $this->idempotency->key, $this->idempotency->fingerprint];
        $this->idempotency->kind = $kind;
        $this->idempotency->key = "pr-gate:{$step}:{$group->id}:{$head}";
        $this->idempotency->fingerprint = hash('sha256', "{$kind}|{$group->id}|{$head}");

        try {
            return $admit();
        } finally {
            [$this->idempotency->kind, $this->idempotency->key, $this->idempotency->fingerprint] = $previous;
        }
    }

    /** @return Result */
    private function result(Task $group, mixed $number, ?string $step, string $result, ?string $code, ?int $operation = null): array
    {
        return ['group' => $group->id, 'pull_request' => is_int($number) ? $number : null, 'step' => $step, 'result' => $result, 'code' => $code, 'operation' => $operation];
    }
}
