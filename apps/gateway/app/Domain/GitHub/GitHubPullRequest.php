<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * The fields of a pull request that Orbit watches while its task group settles.
 */
final readonly class GitHubPullRequest
{
    /**
     * @param  bool|null  $mergeable  null while GitHub still computes whether the pull request merges
     * @param  bool  $draft  whether the pull request is still a draft
     * @param  string|null  $authorLogin  the GitHub login that opened the pull request
     * @param  bool  $fromFork  whether the head branch lives in another repository than the base branch (a deleted fork counts)
     */
    public function __construct(
        public GitHubPullRequestState $state,
        public ?bool $mergeable,
        public ?string $mergeableState,
        public ?string $headSha,
        public ?string $baseRef,
        public ?string $body = null,
        public ?string $mergeCommitSha = null,
        public ?string $mergedAt = null,
        public bool $draft = false,
        public ?string $authorLogin = null,
        public bool $fromFork = false,
    ) {}

    /** Whether GitHub reports that the pull request conflicts with its base branch. */
    public function conflicts(): bool
    {
        return $this->mergeable === false || $this->mergeableState === 'dirty';
    }
}
