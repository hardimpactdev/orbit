<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;

/**
 * Reviews and merges through the Project's App installation (ADR 0203). The write token is the publishing
 * token: `contents`, `pull_requests`, and `workflows` write. Check runs use a `checks: read` token, and the
 * pull request list uses the cached `pull_requests: read` token. No new App permission is needed.
 */
final readonly class GitHubTaskPullRequestMerger implements TaskPullRequestMerger
{
    /**
     * Every caller of one commit's check runs shares one read for this long: the merge gate, the handoff, and the
     * settling watch. The scheduler ticks every few seconds, and each read costs a checks token and a list call.
     */
    private const int CheckRunsSeconds = 60;

    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
    ) {}

    public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
    {
        try {
            $runs = $this->checkRuns($group, $sha);
        } catch (TaskPullRequestException) {
            return RequiredCheckState::Unreadable;
        }

        return $runs === null ? RequiredCheckState::Unreadable : RequiredCheckState::of($runs, $sha, $checkName);
    }

    public function checkRuns(Task $group, string $sha): ?array
    {
        $repository = $this->repository($group->project);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }
        $key = 'tasks:commit-check-runs:v1:'.$repository->owner.'/'.$repository->name.'@'.$sha;
        $cached = Cache::get($key);
        if (is_array($cached) && array_is_list($cached)) {
            $runs = [];
            foreach ($cached as $row) {
                $run = GitHubCheckRun::fromArray($row);
                if (! $run instanceof GitHubCheckRun) {
                    // A value this read did not write: read GitHub again.
                    $runs = null;
                    break;
                }
                $runs[] = $run;
            }
            if ($runs !== null) {
                return $runs;
            }
        }
        $token = $this->access->checksToken($repository);
        if ($token === null) {
            // Not cached, so the permission is checked again on the next read.
            return null;
        }
        try {
            $runs = $this->github->checkRuns($token, $repository, $sha);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('Orbit could not read the check runs of '.$sha.': '.$exception->getMessage(), previous: $exception);
        }
        // Only the runs are cached, never a token. A new push has a new commit, so it reads fresh.
        Cache::put($key, array_map(static fn (GitHubCheckRun $run): array => $run->toArray(), $runs), self::CheckRunsSeconds);

        return $runs;
    }

    public function pullRequest(Task $group, int $number): GitHubPullRequest
    {
        $repository = $this->repository($group->project);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }

        try {
            return $this->github->pullRequest($this->access->token($repository), $repository, $number);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('Orbit could not read pull request #'.$number.': '.$exception->getMessage(), previous: $exception);
        }
    }

    public function merge(Task $group, string $sha): GitHubMergeResult
    {
        [$repository, $number] = $this->storedPullRequest($group);

        try {
            return $this->github->mergePullRequest($this->access->token($repository), $repository, $number, $sha);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request could not be merged: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function review(Task $group, string $sha, GitHubReviewEvent $event, string $body): int
    {
        [$repository, $number] = $this->storedPullRequest($group);

        try {
            return $this->github->submitReview($this->access->token($repository), $repository, $number, $sha, $event, $body);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request review could not be submitted: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function openPullRequests(Project $project): array
    {
        $repository = $this->repository($project);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }

        try {
            return $this->github->openPullRequests($this->access->cachedReadToken($repository), $repository);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The open pull requests could not be listed: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function greenDefaultTipAfter(Task $group, string $sha, string $checkName): ?string
    {
        $repository = $this->repository($group->project);
        $default = $group->project->default_branch;
        if (! $repository instanceof GitHubRepository || ! is_string($default) || $default === '') {
            return null;
        }

        try {
            $token = $this->access->readToken($repository);
            $tip = array_first($this->github->branchCommits($token, $repository, $default))?->sha;
            if (! is_string($tip) || ! $this->github->compareCommits($token, $repository, $sha, $tip)->headDescendsFromBase()) {
                return null;
            }
        } catch (GitHubApiException) {
            return null;
        }

        return $this->requiredCheck($group, $tip, $checkName) === RequiredCheckState::Passed ? $tip : null;
    }

    /**
     * @return array{GitHubRepository, int}
     *
     * @throws TaskPullRequestException
     */
    private function storedPullRequest(Task $group): array
    {
        $repository = $this->repository($group->project);
        $number = $repository instanceof GitHubRepository && is_string($group->pr_url) ? $repository->pullRequestNumber($group->pr_url) : null;
        if (! $repository instanceof GitHubRepository || $number === null) {
            throw new TaskPullRequestException('The task has no pull request on its Project repository.');
        }

        return [$repository, $number];
    }

    private function repository(Project $project): ?GitHubRepository
    {
        return GitHubRepository::fromOrigin((string) $project->repository_url);
    }
}
