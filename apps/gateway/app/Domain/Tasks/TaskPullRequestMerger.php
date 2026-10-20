<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckRun;
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
 * Every call mints its own token. No call holds a database lock.
 */
interface TaskPullRequestMerger
{
    /** The merge check on exactly this head. A failed read is `Unreadable`, never `Passed`. */
    public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState;

    /**
     * Every check run on exactly this commit. A handoff reads its merge check and its failed runs from one read.
     *
     * @return list<GitHubCheckRun>
     *
     * @throws TaskPullRequestException when the App or GitHub is unavailable
     */
    public function checkRuns(Task $group, string $sha): array;

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
