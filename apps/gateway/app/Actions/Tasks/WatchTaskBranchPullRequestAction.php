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
        $moved = $this->movedPullRequestNumber($group, $repository);
        $open = $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true));
        if ($moved === null && ! $open) {
            return;
        }
        try {
            if (! Cache::add('tasks:branch-pull-request:'.$group->id, true, 60)) {
                return;
            }
            $pullRequests = $this->github->pullRequestsByHead(
                $this->access->cachedReadToken($repository), $repository, TaskRemoteBranch::for($group),
            );
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
        // GitHub keeps the number when it transfers a repository. The task branch has a pull request
        // with that number here, so it is the same pull request.
        $followed = null;
        if ($moved !== null) {
            foreach ($pullRequests as $pullRequest) {
                if ($pullRequest->number === $moved) {
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
     * The number in pr_url when it names a pull request in another repository, as it does after the
     * repository moved. Null when pr_url is unset or belongs to this repository.
     */
    private function movedPullRequestNumber(Task $group, GitHubRepository $repository): ?int
    {
        $url = $group->pr_url;
        if (! is_string($url) || $url === '' || $repository->pullRequestNumber($url) !== null
            || preg_match('#\Ahttps://github\.com/[A-Za-z0-9-]+/[A-Za-z0-9._-]+/pull/([1-9][0-9]{0,9})\z#D', $url, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
