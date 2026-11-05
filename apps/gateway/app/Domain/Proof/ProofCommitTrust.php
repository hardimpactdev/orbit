<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Shared\ResourceOperationException;

/**
 * Orbit proves only code it can trust: the head of a branch of its own repository, or the head of an open pull
 * request that carries the label and a maintainer's approval of exactly that commit. A pull request from outside can
 * carry any code, so a new push needs a new approval.
 */
final readonly class ProofCommitTrust
{
    public function __construct(private ProofCommitSource $source, private ProofSettings $settings) {}

    /** @throws ResourceOperationException proof.commit_not_found, proof.commit_untrusted, proof.github_unavailable */
    public function assert(string $sha): void
    {
        try {
            if (! $this->source->commitExists($sha)) {
                throw new ResourceOperationException('proof.commit_not_found', 'The repository has no commit '.$sha.'.', 422);
            }
            if ($this->source->branchesWithHead($sha) !== [] || $this->approvedPullRequest($sha)) {
                return;
            }
        } catch (GitHubApiException) {
            throw new ResourceOperationException('proof.github_unavailable', 'GitHub could not be read, so the commit cannot be checked.', 503);
        }

        throw new ResourceOperationException('proof.commit_untrusted', 'The commit is not the head of a branch of the repository, nor the head of an open pull request with the label ['.$this->settings->label().'] and a maintainer\'s approval of exactly that commit.', 403);
    }

    private function approvedPullRequest(string $sha): bool
    {
        return array_any($this->source->pullRequestsWithHead($sha), fn ($pullRequest) => $pullRequest->open && $pullRequest->headSha === $sha && in_array($this->settings->label(), $pullRequest->labels, true)
            && $this->approvedByMaintainer($pullRequest->number, $sha));
    }

    /** Each reviewer's latest decision counts: a later change request or dismissal ends an approval. */
    private function approvedByMaintainer(int $pullRequest, string $sha): bool
    {
        $latest = [];
        foreach ($this->source->reviews($pullRequest) as $review) {
            if (! in_array($review->state, [GitHubReviewState::Approved, GitHubReviewState::ChangesRequested, GitHubReviewState::Dismissed], true)) {
                continue;
            }
            $current = $latest[$review->reviewerLogin] ?? null;
            if (! $current instanceof GitHubReview || self::after($review, $current)) {
                $latest[$review->reviewerLogin] = $review;
            }
        }

        return array_any($latest, fn ($review, $login) => $review->state === GitHubReviewState::Approved && $review->commitId === $sha && $this->source->canWrite((string) $login));
    }

    private static function after(GitHubReview $review, GitHubReview $than): bool
    {
        return [$review->submittedAt?->getTimestamp() ?? 0, $review->id] > [$than->submittedAt?->getTimestamp() ?? 0, $than->id];
    }
}
