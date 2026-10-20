<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;

/**
 * What the draft pull request that runs a handoff's CI reports for the handoff commit
 * ([Handoff CI](/reference/tasks#handoff-ci)).
 */
final readonly class TaskHandoffPullRequest
{
    /**
     * @param  bool  $current  whether the pull request is open with the handoff commit as its head. Otherwise its CI
     *                         cannot test that commit, and Orbit publishes the commit again.
     * @param  string|null  $conflictBase  the base branch that the current pull request conflicts with
     */
    public function __construct(
        public bool $current,
        public ?string $conflictBase,
    ) {}

    public static function of(GitHubPullRequest $pullRequest, string $sha): self
    {
        $current = $pullRequest->state === GitHubPullRequestState::Open && $pullRequest->headSha === $sha;

        return new self($current, $current && $pullRequest->conflicts() ? ($pullRequest->baseRef ?? 'the base branch') : null);
    }
}
