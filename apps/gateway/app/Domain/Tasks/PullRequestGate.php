<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;

/**
 * The manual gate of a task pull request: the reviewer's latest decision is an approval of the exact head.
 * A comment changes no decision. A push moves the head, so an approval of an older head does not count.
 */
final readonly class PullRequestGate
{
    /** @param list<GitHubReview> $reviews */
    public static function manual(array $reviews, string $head, string $reviewer): PullRequestGateState
    {
        $decisions = array_values(array_filter($reviews, static fn (GitHubReview $review): bool => strcasecmp($review->reviewerLogin, $reviewer) === 0
            && $review->submittedAt !== null
            && in_array($review->state, [GitHubReviewState::Approved, GitHubReviewState::ChangesRequested, GitHubReviewState::Dismissed], true)));
        usort($decisions, static fn (GitHubReview $a, GitHubReview $b): int => [$a->submittedAt, $a->id] <=> [$b->submittedAt, $b->id]);
        $latest = array_last($decisions);
        if ($latest === null) {
            return PullRequestGateState::Waiting;
        }

        return match ($latest->state) {
            GitHubReviewState::Approved => $latest->commitId === $head ? PullRequestGateState::Approved : PullRequestGateState::HeadMoved,
            GitHubReviewState::ChangesRequested => PullRequestGateState::ChangesRequested,
            default => PullRequestGateState::Waiting,
        };
    }
}
