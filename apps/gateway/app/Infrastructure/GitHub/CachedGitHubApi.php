<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubOpenedPullRequest;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\Tasks\TaskBranchUpdate;
use SensitiveParameter;

/**
 * The GitHub API with the repository's installation and its `contents: read` and `checks: read` tokens kept in
 * {@see GitHubTokenCache}. Only reads that run on a schedule use it: the automatic deployment tick's branch head and
 * green-commit reads. Every other call goes to GitHub as before.
 */
final readonly class CachedGitHubApi implements GitHubApi
{
    /** The permissions whose tokens are cached. */
    public const array Permissions = ['contents', 'checks'];

    public function __construct(private GitHubApi $github, private GitHubTokenCache $tokens) {}

    public function convertManifest(#[SensitiveParameter] string $code): GitHubAppCredentials
    {
        return $this->github->convertManifest($code);
    }

    public function installations(GitHubAppCredentials $credentials): array
    {
        return $this->github->installations($credentials);
    }

    public function repositoryInstallation(GitHubAppCredentials $credentials, GitHubRepository $repository): ?int
    {
        return $this->tokens->installation($credentials->appId, $repository, fn (): ?int => $this->github->repositoryInstallation($credentials, $repository));
    }

    public function repositoryReadToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->tokens->token($credentials->appId, $installationId, $repository, 'contents', fn (): string => $this->github->repositoryReadToken($credentials, $installationId, $repository));
    }

    public function repositoryMetadataToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->github->repositoryMetadataToken($credentials, $installationId, $repository);
    }

    public function repositoryAdministrationReadToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->github->repositoryAdministrationReadToken($credentials, $installationId, $repository);
    }

    public function rulesRequireUpToDate(#[SensitiveParameter] string $token, GitHubRepository $repository, string $branch): bool
    {
        return $this->github->rulesRequireUpToDate($token, $repository, $branch);
    }

    public function protectionRequiresUpToDate(#[SensitiveParameter] string $token, GitHubRepository $repository, string $branch): bool
    {
        return $this->github->protectionRequiresUpToDate($token, $repository, $branch);
    }

    public function repositoryPullRequestToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->github->repositoryPullRequestToken($credentials, $installationId, $repository);
    }

    public function repositoryPullRequestReadToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->github->repositoryPullRequestReadToken($credentials, $installationId, $repository);
    }

    public function pullRequestsByHead(#[SensitiveParameter] string $token, GitHubRepository $repository, string $head): array
    {
        return $this->github->pullRequestsByHead($token, $repository, $head);
    }

    public function repositoryChecksToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->tokens->token($credentials->appId, $installationId, $repository, 'checks', fn (): string => $this->github->repositoryChecksToken($credentials, $installationId, $repository));
    }

    public function repositoryReviewsToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->github->repositoryReviewsToken($credentials, $installationId, $repository);
    }

    public function reviews(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): array
    {
        return $this->github->reviews($token, $repository, $number);
    }

    public function review(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): GitHubReview
    {
        return $this->github->review($token, $repository, $number, $reviewId);
    }

    public function reviewComments(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): array
    {
        return $this->github->reviewComments($token, $repository, $number, $reviewId);
    }

    public function openPullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubPullRequestDraft $draft): GitHubOpenedPullRequest
    {
        return $this->github->openPullRequest($token, $repository, $draft);
    }

    public function requestPullRequestReviewers(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, array $reviewers): void
    {
        $this->github->requestPullRequestReviewers($token, $repository, $number, $reviewers);
    }

    public function updatePullRequestBranch(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $headSha): TaskBranchUpdate
    {
        return $this->github->updatePullRequestBranch($token, $repository, $number, $headSha);
    }

    public function pullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): GitHubPullRequest
    {
        return $this->github->pullRequest($token, $repository, $number);
    }

    public function checkRuns(#[SensitiveParameter] string $token, GitHubRepository $repository, string $sha, ?string $checkName = null): array
    {
        return $this->github->checkRuns($token, $repository, $sha, $checkName);
    }

    public function branchCommits(#[SensitiveParameter] string $token, GitHubRepository $repository, string $branch): array
    {
        return $this->github->branchCommits($token, $repository, $branch);
    }

    public function compareCommits(#[SensitiveParameter] string $token, GitHubRepository $repository, string $baseSha, string $headSha): GitHubCommitComparison
    {
        return $this->github->compareCommits($token, $repository, $baseSha, $headSha);
    }

    public function openPullRequests(#[SensitiveParameter] string $token, GitHubRepository $repository): array
    {
        return $this->github->openPullRequests($token, $repository);
    }

    public function submitReview(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $commitId, GitHubReviewEvent $event, string $body): int
    {
        return $this->github->submitReview($token, $repository, $number, $commitId, $event, $body);
    }

    public function mergePullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $sha): GitHubMergeResult
    {
        return $this->github->mergePullRequest($token, $repository, $number, $sha);
    }
}
