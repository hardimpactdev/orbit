<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Models\Project;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the check runs of a commit through the Project's App installation (ADR 0203). Uncached commit snapshots use
 * separate `checks: read` and `actions: read` tokens.
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

    public function checkRuns(Task $group, string $sha): ?array
    {
        return $this->commitChecks($group, $sha)['runs'] ?? null;
    }

    /**
     * Check runs, check suites, and workflow identities share one snapshot. A missing `checks: read` permission is
     * not cached, so the next read checks it again.
     *
     * @return array{runs: list<GitHubCheckRun>, suites: list<GitHubCheckSuite>, pull_request_suites: list<int>}|null
     */
    private function commitChecks(Task $group, string $sha): ?array
    {
        $repository = $this->githubRepository($group);
        $key = 'tasks:commit-checks:v3:'.$repository->owner.'/'.$repository->name.'@'.$sha;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $runs = self::decoded($cached['runs'] ?? null, GitHubCheckRun::fromArray(...));
            $suites = self::decoded($cached['suites'] ?? null, GitHubCheckSuite::fromArray(...));
            $pullRequestSuites = $cached['pull_request_suites'] ?? null;
            if ($runs !== null && $suites !== null && is_array($pullRequestSuites) && array_is_list($pullRequestSuites) && array_all($pullRequestSuites, static fn (mixed $id): bool => is_int($id))) {
                return ['runs' => $runs, 'suites' => $suites, 'pull_request_suites' => $pullRequestSuites];
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
        $workflows = $this->workflowSuites($repository, $sha);
        $superseded = $workflows['superseded'];
        $snapshot = [
            'runs' => array_values(array_filter($snapshot['runs'], static fn (GitHubCheckRun $run): bool => ! in_array($run->checkSuiteId, $superseded, true))),
            'suites' => array_values(array_filter($suites, static fn (GitHubCheckSuite $suite): bool => ! in_array($suite->id, $superseded, true))),
            'pull_request_suites' => $workflows['pull_request'],
        ];
        Cache::put($key, [
            'runs' => array_map(static fn (GitHubCheckRun $run): array => $run->toArray(), $snapshot['runs']),
            'suites' => array_map(static fn (GitHubCheckSuite $suite): array => $suite->toArray(), $snapshot['suites']),
            'pull_request_suites' => $snapshot['pull_request_suites'],
        ], self::ReadSeconds);

        return $snapshot;
    }

    /**
     * Older suites of the same workflow, event, and branch cannot decide a commit's checks. The same workflow read
     * identifies the pull request's suites. At most one page of 100 runs is read; an incomplete list fails the read.
     *
     * @return array{superseded: list<int>, pull_request: list<int>}
     */
    private function workflowSuites(GitHubRepository $repository, string $sha): array
    {
        try {
            $read = $this->actions->json(GitHubActionsReader::repositoryPath($repository).'/actions/runs?'.http_build_query([
                'head_sha' => $sha, 'per_page' => 100,
            ]), $this->actions->token($repository));
        } catch (GitHubActionsUnavailable $exception) {
            throw new TaskPullRequestException('Orbit could not read the workflow runs of '.$sha.'.', previous: $exception);
        }
        $rows = $read['workflow_runs'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! is_int($read['total_count'] ?? null) || $read['total_count'] !== count($rows)) {
            throw new TaskPullRequestException('Orbit could not read the workflow runs of '.$sha.'.');
        }
        $latest = [];
        $superseded = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['head_sha'] ?? null) !== $sha || ! is_int($row['id'] ?? null)
                || ! is_int($row['workflow_id'] ?? null) || ! is_int($row['check_suite_id'] ?? null)
                || ! is_string($row['event'] ?? null) || ! array_key_exists('head_branch', $row)
                || ($row['head_branch'] !== null && ! is_string($row['head_branch']))) {
                throw new TaskPullRequestException('Orbit could not read the workflow runs of '.$sha.'.');
            }
            $key = json_encode([$row['workflow_id'], $row['event'], $row['head_branch']], JSON_THROW_ON_ERROR);
            $prior = $latest[$key] ?? null;
            if ($prior === null || $row['id'] > $prior['id']) {
                if ($prior !== null && $prior['check_suite_id'] !== $row['check_suite_id']) {
                    $superseded[] = $prior['check_suite_id'];
                }
                $latest[$key] = $row;
            } elseif ($prior['check_suite_id'] !== $row['check_suite_id']) {
                $superseded[] = $row['check_suite_id'];
            }
        }

        return [
            'superseded' => array_values(array_unique($superseded)),
            'pull_request' => array_values(array_map(static fn (array $row): int => $row['check_suite_id'],
                array_filter($latest, static fn (array $row): bool => $row['event'] === 'pull_request'))),
        ];
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

    private function repository(Project $project): ?GitHubRepository
    {
        return GitHubRepository::fromOrigin((string) $project->repository_url);
    }
}
