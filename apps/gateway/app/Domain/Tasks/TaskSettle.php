<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One tick of the settle on merge of task copy groups (docs/reference/tasks.md#a-copy-group-settles-on-merge), which the Gateway
 * scheduler runs as `task:copy-settle` for every Project with a GitHub repository, whatever its maintenance setting. It reads each
 * watched pull request with a read-only App token and never merges, readies or comments on it. A merged one is recorded, and the
 * group completes through task:complete's own action, which admits the copy's destroy. Every step is safe to repeat, so the next
 * tick retries what did not finish.
 *
 * @phpstan-type Result array{group: int, result: string, code: string|null}
 */
final readonly class TaskSettle
{
    /** Copy reads that a person must fix: the copy is stopped or asleep, or holds no repository. A timeout or a lost Node retries. */
    private const array StuckReads = ['stopped', 'asleep', 'not_a_repository'];

    public function __construct(private GitHubApi $github, private RepositoryPullRequestAccess $access) {}

    /** @return list<Result> */
    public function execute(): array
    {
        $groups = Task::topLevel()->with('project')->whereNotNull('watched_pr_url')->where(static fn (Builder $query) => $query
            ->whereIn('status', [TaskGroupStatus::Running, TaskGroupStatus::Reviewing])
            ->orWhere(static fn (Builder $kept) => $kept->where('status', TaskGroupStatus::Completed)->whereNotNull('taskable_id')->where('watched_pr_completion', 'merged')))
            ->orderBy('id')->get();
        $results = [];
        foreach ($groups as $group) {
            $results[] = ['group' => $group->id, ...$this->settle($group)];
        }

        return $results;
    }

    /** @return array{result: string, code: string|null} */
    private function settle(Task $group): array
    {
        if (TaskInstanceWorkspace::forGroup($group) === null) {
            return ['result' => 'skipped', 'code' => 'tasks.no_copy'];
        }
        if ($group->assistance_requested && ! self::removalFailed($group)) {
            return ['result' => 'skipped', 'code' => 'tasks.assistance_requested'];
        }
        if ($group->watched_pr_completion === null) {
            $state = $this->read($group);
            if ($state !== 'merged') {
                return $state === null ? ['result' => 'skipped', 'code' => 'tasks_pr.github_unreadable'] : ['result' => 'observed', 'code' => null];
            }
        } elseif ($group->watched_pr_completion !== 'merged') {
            return ['result' => 'skipped', 'code' => 'tasks.pull_request_closed'];
        }
        if ($this->busy($group->refresh())) {
            return ['result' => 'skipped', 'code' => 'operation.target_busy'];
        }

        return $this->complete($group);
    }

    /**
     * Reads the watched pull request and records its state and, once, when it was opened. A merged one also records the merge and when it happened, under the group's
     * lock, so a crash before the complete leaves it for the next tick. A closed one is recorded only: a person decides. Null when GitHub
     * cannot be read.
     */
    private function read(Task $group): ?string
    {
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        $number = $repository?->pullRequestNumber((string) $group->watched_pr_url);
        if ($repository === null || $number === null) {
            return null;
        }
        try {
            $pull = $this->github->pullRequest($this->access->cachedReadToken($repository), $repository, $number);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
        $state = $pull->state->value;
        DB::transaction(function () use ($group, $state, $number, $pull): void {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            if ($locked->watched_pr_url !== $group->watched_pr_url || $locked->watched_pr_completion !== null) {
                return;
            }
            $merged = $state === 'merged' && in_array($locked->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true);
            $locked->update(['watched_pr_number' => $number, 'watched_pr_state' => $state, 'pr_opened_at' => $locked->pr_opened_at ?? $pull->createdAt,
                ...($merged ? ['watched_pr_completion' => 'merged', 'merged_at' => $locked->merged_at ?? $pull->mergedAt] : [])]);
        });

        return $state;
    }

    /** An open Operation of the group (a turn, a check or a publish), or one that holds the copy (a destroy that runs): the next tick retries. */
    private function busy(Task $group): bool
    {
        $open = static fn (string $type, ?int $id): bool => Operation::query()->exceptFleetWait()->exceptCancelWait()->where('target_type', $type)->where('target_id', $id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();

        return $open(OperationKinds::TaskGroupTarget, $group->id) || $open('instance', $group->taskable_id);
    }

    /**
     * A completed group whose earlier complete could not admit the copy's destroy: the complete records that request, and clears it
     * itself once the removal succeeds, so the tick retries it. Any other request waits for a person.
     */
    private static function removalFailed(Task $group): bool
    {
        return $group->status === TaskGroupStatus::Completed && $group->assistance_kind === AssistanceKind::RemovalFailure
            && str_starts_with((string) $group->assistance_reason, RemoveTaskWorkspaceAction::RemovalFailedPrefix);
    }

    /**
     * Completes the group; a failed destroy leaves it `completed` with its copy for the next tick. Only a copy that a person must fix is
     * recorded, once, as assistance: it holds work that is not published, or it is stopped. Every other refusal, such as a copy read that
     * timed out or a GitHub read of origin's branch that failed, is retried by the next tick.
     *
     * @return array{result: string, code: string|null}
     */
    private function complete(Task $group): array
    {
        try {
            app(CompleteTaskGroupAction::class)->execute($group, finishWhenRemovalFails: false);
        } catch (Throwable $exception) {
            $code = $exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class;
            if ($group->refresh()->status === TaskGroupStatus::Completed) {
                return ['result' => 'completed', 'code' => $exception instanceof TaskWorkspaceWaiting ? null : $code];
            }
            $request = $exception instanceof ResourceOperationException ? $this->request($exception) : null;
            if ($request !== null) {
                TaskAssistance::apply($group, AssistanceKind::Failure, reason: $request);

                return ['result' => 'assistance', 'code' => $code];
            }
            if (! $exception instanceof ResourceOperationException) {
                report($exception);
            }

            return ['result' => 'skipped', 'code' => $code];
        }

        return ['result' => 'completed', 'code' => null];
    }

    /** The request for a person, or null for a refusal that the next tick retries. The merged work is already on the default branch. */
    private function request(ResourceOperationException $refusal): ?string
    {
        if ($refusal->errorCode === 'tasks.copy_unpublished_work') {
            return 'The watched pull request merged, but the task copy holds work that is not on the default branch or origin: '
                .json_encode($refusal->details, JSON_THROW_ON_ERROR).'. Publish or remove that work, then resolve this request; the next settle completes the group.';
        }
        $reason = $refusal->details['reason'] ?? null;
        if ($refusal->errorCode === 'tasks.copy_workspace_unknown' && is_string($reason) && in_array($reason, self::StuckReads, true)) {
            return 'The watched pull request merged, but the task copy cannot be read ('.$reason.'). Start the copy, then resolve this request; the next settle completes the group.';
        }

        return null;
    }
}
