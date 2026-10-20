<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Models\Project;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Reviews and merges through the Project's App installation (ADR 0203). The write token is the publishing
 * token: `contents`, `pull_requests`, and `workflows` write. Check runs use a `checks: read` token, and the
 * pull request list uses the cached `pull_requests: read` token. No new App permission is needed.
 */
final readonly class GitHubTaskPullRequestMerger implements TaskPullRequestMerger
{
    /**
     * Every reader of one commit's checks shares one read of each part for this long: the merge gate, the handoff CI,
     * and the settling watch. The scheduler ticks every few seconds, and each read costs a token and a list call.
     * Only check data is cached, never a token. A new push has a new commit, so it reads fresh.
     */
    private const int ReadSeconds = 60;

    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private GitHubActionsReader $actions,
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

    public function pullRequestCheck(Task $group, string $sha, string $checkName): RequiredCheckState
    {
        try {
            $runs = $this->checkRuns($group, $sha);
            if ($runs === null) {
                return RequiredCheckState::Unreadable;
            }
            $suites = $this->pullRequestSuites($group, $sha);
        } catch (TaskPullRequestException) {
            return RequiredCheckState::Unreadable;
        }
        // Another App's run of the name still disqualifies the commit, as the green-commit rules say.
        $counted = array_values(array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->name !== $checkName
            || $run->appSlug !== RequiredCheckState::CheckApp || in_array($run->checkSuiteId, $suites, true)));

        return RequiredCheckState::of($counted, $sha, $checkName);
    }

    /**
     * The check suites of the `pull_request` workflow runs for exactly this head, shared for a minute like the checks.
     * It reads at most one page of 100 runs; more fails the read.
     *
     * @return list<int>
     *
     * @throws TaskPullRequestException
     */
    private function pullRequestSuites(Task $group, string $sha): array
    {
        $repository = $this->githubRepository($group);
        $key = 'tasks:commit-checks:v1:'.$repository->owner.'/'.$repository->name.'@'.$sha.':pull-request-suites';
        $cached = Cache::get($key);
        if (is_array($cached) && array_is_list($cached) && array_all($cached, is_int(...))) {
            return $cached;
        }
        try {
            $read = $this->actions->json(GitHubActionsReader::repositoryPath($repository).'/actions/runs?'.http_build_query([
                'head_sha' => $sha, 'event' => 'pull_request', 'per_page' => 100,
            ]), $this->actions->token($repository));
        } catch (GitHubActionsUnavailable $exception) {
            throw new TaskPullRequestException('Orbit could not read the workflow runs of '.$sha.'.', previous: $exception);
        }
        $rows = $read['workflow_runs'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! is_int($read['total_count'] ?? null) || $read['total_count'] !== count($rows)) {
            throw new TaskPullRequestException('Orbit could not read the workflow runs of '.$sha.'.');
        }
        $suites = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['event'] ?? null) === 'pull_request' && ($row['head_sha'] ?? null) === $sha && is_int($row['check_suite_id'] ?? null)) {
                $suites[] = $row['check_suite_id'];
            }
        }
        Cache::put($key, $suites, self::ReadSeconds);

        return $suites;
    }

    public function checkRuns(Task $group, string $sha): ?array
    {
        return $this->commitChecks($group, $sha)['runs'] ?? null;
    }

    /**
     * The check runs and check suites are read together, so the two lists never come from different moments. A
     * missing `checks: read` permission is not cached, so the next read checks it again.
     */
    public function commitChecks(Task $group, string $sha): ?array
    {
        $repository = $this->githubRepository($group);
        $key = 'tasks:commit-checks:v1:'.$repository->owner.'/'.$repository->name.'@'.$sha;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $runs = self::decoded($cached['runs'] ?? null, GitHubCheckRun::fromArray(...));
            $suites = self::decoded($cached['suites'] ?? null, GitHubCheckSuite::fromArray(...));
            if ($runs !== null && $suites !== null) {
                return ['runs' => $runs, 'suites' => $suites];
            }
        }
        $token = $this->access->checksToken($repository);
        if ($token === null) {
            return null;
        }
        try {
            // Suites first: GitHub completes a suite only after its runs, so a completed suite always comes with its final runs.
            $suites = $this->github->checkSuites($token, $repository, $sha);
            $snapshot = ['runs' => $this->github->checkRuns($token, $repository, $sha), 'suites' => $suites];
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('Orbit could not read the checks of '.$sha.': '.$exception->getMessage(), previous: $exception);
        }
        Cache::put($key, [
            'runs' => array_map(static fn (GitHubCheckRun $run): array => $run->toArray(), $snapshot['runs']),
            'suites' => array_map(static fn (GitHubCheckSuite $suite): array => $suite->toArray(), $snapshot['suites']),
        ], self::ReadSeconds);

        return $snapshot;
    }

    /** @throws TaskPullRequestException */
    private function githubRepository(Task $group): GitHubRepository
    {
        return $this->repository($group->project) ?? throw new TaskPullRequestException('The Project repository is not on github.com.');
    }

    /**
     * The list a read stored, or null to read GitHub again, also for a value that read did not write.
     *
     * @template T of object
     *
     * @param  Closure(mixed): (T|null)  $fromArray
     * @return list<T>|null
     */
    private static function decoded(mixed $cached, Closure $fromArray): ?array
    {
        if (! is_array($cached) || ! array_is_list($cached)) {
            return null;
        }
        $items = [];
        foreach ($cached as $row) {
            $item = $fromArray($row);
            if ($item === null) {
                return null;
            }
            $items[] = $item;
        }

        return $items;
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
