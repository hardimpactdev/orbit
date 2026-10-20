<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Project;
use App\Models\Task;

/**
 * The GitHub App operations of the review-and-merge flow (ADR 0203): read the merge check of one head or handoff commit,
 * submit a review decision on an incoming pull request, merge,
 * and list a Project's open pull requests.
 * GitHub calls use repository-scoped installation tokens. No call holds a database lock.
 */
interface TaskPullRequestMerger
{
    /** The merge check on exactly this commit, from {@see checkRuns()}. A failed read is `Unreadable`, never `Passed`. */
    public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState;

    /**
     * The merge check of the group's pull request on its head `$sha`, as {@see requiredCheck()} judges it, but only from
     * the runs of a `pull_request` workflow run for exactly that head. Those test the pull request's merge ref. A run of
     * a `push` on the same commit, such as a handoff push, never counts. A failed read is `Unreadable`, never `Passed`.
     */
    public function pullRequestCheck(Task $group, string $sha, string $checkName): RequiredCheckState;

    /**
     * The check runs on exactly this commit after {@see commitChecks()} removes superseded workflow suites.
     * The merge gate, handoff CI, and settling watch share one read per repository and commit for a minute.
     * Null when the installation has not granted `checks: read`. Uncached reads also need `actions: read`.
     *
     * @return list<GitHubCheckRun>|null
     *
     * @throws TaskPullRequestException when the App or GitHub is unavailable
     */
    public function checkRuns(Task $group, string $sha): ?array;

    /**
     * Check runs, check suites, and pull request suite IDs on exactly this commit, as one shared snapshot.
     * For each GitHub Actions workflow, event, and branch, only the newest workflow run's suite and jobs remain.
     * Null when the installation has not granted `checks: read`. Uncached snapshots also need `actions: read`.
     *
     * @return array{runs: list<GitHubCheckRun>, suites: list<GitHubCheckSuite>, pull_request_suites: list<int>}|null
     *
     * @throws TaskPullRequestException when the App or GitHub is unavailable
     */
    public function commitChecks(Task $group, string $sha): ?array;

    /**
     * Merges the group's pull request with a merge commit while its head is `$sha`.
     *
     * @throws TaskPullRequestException when the App or GitHub is unavailable
     */
    public function merge(Task $group, string $sha): GitHubMergeResult;

    /**
     * Submits an `APPROVE` or `REQUEST_CHANGES` review for exactly `$sha` and returns its review id.
     *
     * @throws TaskPullRequestException
     */
    public function review(Task $group, string $sha, GitHubReviewEvent $event, string $body): int;

    /**
     * @return list<GitHubListedPullRequest>
     *
     * @throws TaskPullRequestException
     */
    public function openPullRequests(Project $project): array;

    /**
     * The default branch tip when it strictly descends from `$sha` and `$checkName` passed on it, or null.
     * A failed read is null. A baseline that failed on a red `$sha` retries on that tip.
     */
    public function greenDefaultTipAfter(Task $group, string $sha, string $checkName): ?string;
}
