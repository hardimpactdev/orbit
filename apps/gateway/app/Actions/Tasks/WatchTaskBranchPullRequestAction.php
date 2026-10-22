<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

final readonly class WatchTaskBranchPullRequestAction
{
    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function execute(Task $group): void
    {
        if (RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)) {
            return;
        }
        $group->loadMissing(['project', 'tasks']);
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        if ($repository === null) {
            return;
        }
        $moved = $this->movedPullRequest($group, $repository);
        $open = $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true));
        if ($moved === null && ! $open) {
            return;
        }
        try {
            if (! Cache::add('tasks:branch-pull-request:'.$group->id, true, 60)) {
                return;
            }
            $token = $this->access->cachedReadToken($repository);
            $pullRequests = $this->github->pullRequestsByHead($token, $repository, TaskRemoteBranch::for($group));
        } catch (Throwable) {
            return;
        }
        $selected = $pullRequests[0] ?? null;
        foreach ($pullRequests as $pullRequest) {
            if ($pullRequest->state === GitHubPullRequestState::Open) {
                $selected = $pullRequest;
                break;
            }
        }
        $attributes = $open && $selected !== null ? [
            'watched_pr_url' => $selected->url,
            'watched_pr_number' => $selected->number,
            'watched_pr_state' => $selected->state->value,
        ] : [];
        // GitHub keeps the number when it transfers a repository. A number and a branch name do not prove
        // a move, so GitHub must also resolve the old repository to this one.
        $followed = null;
        if ($moved !== null) {
            foreach ($pullRequests as $pullRequest) {
                if ($pullRequest->number === $moved[1] && $this->sameRepository($token, $repository, $moved[0])) {
                    $followed = $pullRequest->url;
                    $attributes['pr_url'] = $followed;
                    break;
                }
            }
        }
        if ($attributes === []) {
            return;
        }
        $previous = $group->pr_url;
        $group->update($attributes);
        if ($followed !== null) {
            Log::warning('A task pull request URL followed its repository.', ['task_group_id' => $group->id, 'from' => $previous, 'to' => $followed]);
        }
    }

    /**
     * The repository and number in pr_url when it names a pull request in another repository, as it does after
     * the repository moved. Null when pr_url is unset or belongs to this repository.
     *
     * @return array{GitHubRepository, int}|null
     */
    private function movedPullRequest(Task $group, GitHubRepository $repository): ?array
    {
        $url = $group->pr_url;
        if (! is_string($url) || $url === '' || $repository->pullRequestNumber($url) !== null
            || preg_match('#\Ahttps://github\.com/([A-Za-z0-9-]+/[A-Za-z0-9._-]+)/pull/([1-9][0-9]{0,9})\z#D', $url, $matches) !== 1) {
            return null;
        }
        $previous = GitHubRepository::fromOrigin('https://github.com/'.$matches[1]);

        return $previous === null ? null : [$previous, (int) $matches[2]];
    }

    /**
     * Whether GitHub resolves the old repository, through its transfer redirect, to the Project repository.
     * An answer is kept for an hour, so a move that cannot be proved does not cost two reads every minute.
     */
    private function sameRepository(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubRepository $previous): bool
    {
        $key = 'tasks:repository-move:'.strtolower($previous->owner.'/'.$previous->name.'>'.$repository->owner.'/'.$repository->name);
        $known = Cache::get($key);
        if (is_bool($known)) {
            return $known;
        }
        try {
            $id = $this->github->repositoryId($token, $repository);
            $same = $id !== null && $this->github->repositoryId($token, $previous) === $id;
        } catch (Throwable) {
            return false;
        }
        Cache::put($key, $same, 3600);

        return $same;
    }
}
