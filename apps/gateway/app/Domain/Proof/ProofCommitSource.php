<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubReview;

/**
 * What `proof:run` reads from the Orbit repository on GitHub through the Gateway's App. It fetches no code.
 *
 * @throws GitHubApiException when GitHub, the App or its installation cannot answer
 */
interface ProofCommitSource
{
    public function commitExists(string $sha): bool;

    /** @return list<string> the branches of the repository whose head is exactly this commit */
    public function branchesWithHead(string $sha): array;

    /** @return list<ProofPullRequest> the pull requests that have this commit as their head, in any state */
    public function pullRequestsWithHead(string $sha): array;

    /** Whether the user has write access to the repository. */
    public function canWrite(string $login): bool;

    /** @return list<GitHubReview> */
    public function reviews(int $pullRequest): array;

    /** The diff of the commit against its merge base with the base branch. */
    public function changes(string $sha): ProofChangedFiles;

    /** Whether `$sha` is `$base` or comes after it on its history; false when GitHub does not know `$sha`. */
    public function atOrAfter(string $base, string $sha): bool;

    /** The text of one file at the commit, or null when the commit has no such file. */
    public function file(string $sha, string $path): ?string;

    /** @return list<string> every file path of the commit's `swamp/proof` tree */
    public function proofPaths(string $sha): array;
}
