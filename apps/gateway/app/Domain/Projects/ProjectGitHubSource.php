<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Project;

/**
 * Reads a Project's repository through the Gateway GitHub App: the repository's installation, and the newest commit of
 * a branch that passed a check. Automatic deploy source reads and task provision admission share these rules.
 */
final readonly class ProjectGitHubSource
{
    /** Bounds one first-parent walk. Older commits wait for a newer one. */
    public const int CandidateLimit = 20;

    public function __construct(private GitHubAppStore $store, private GitHubApi $github) {}

    /**
     * @return array{GitHubRepository, GitHubAppCredentials, int}|null null when the Project does not read a github.com repository through the GitHub App
     *
     * @throws GitHubApiException when the App is not registered or not installed on the repository, or GitHub cannot answer
     */
    public function access(Project $project): ?array
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        if ($project->source_access !== ProjectSourceAccess::GitHubApp || ! $repository instanceof GitHubRepository) {
            return null;
        }
        $credentials = $this->store->credentials() ?? throw new GitHubApiException('The Gateway GitHub App is not registered.');
        $installation = $this->github->repositoryInstallation($credentials, $repository) ?? throw new GitHubApiException('The repository has no GitHub App installation.');

        return [$repository, $credentials, $installation];
    }

    /**
     * The newest commit on the branch's first-parent history, from its head, whose check `$checkName` passed on exactly
     * that commit by {@see RequiredCheckState::of()}. At most {@see CandidateLimit} commits are examined.
     *
     * @param  array{GitHubRepository, GitHubAppCredentials, int}  $access
     * @return string|null null when no examined commit passed
     *
     * @throws GitHubApiException
     */
    public function newestGreen(array $access, string $branch, string $checkName): ?string
    {
        [$repository, $credentials, $installation] = $access;
        $commits = [];
        $read = $this->github->repositoryReadToken($credentials, $installation, $repository);
        foreach ($this->github->branchCommits($read, $repository, $branch) as $commit) {
            $commits[$commit->sha] ??= $commit;
        }
        $checks = null;
        $candidate = array_first($commits);
        for ($examined = 0; $candidate instanceof GitHubCommit && $examined < self::CandidateLimit; $examined++) {
            $checks ??= $this->github->repositoryChecksToken($credentials, $installation, $repository);
            $runs = $this->github->checkRuns($checks, $repository, $candidate->sha, $checkName);
            if (RequiredCheckState::of($runs, $candidate->sha, $checkName) === RequiredCheckState::Passed) {
                return $candidate->sha;
            }
            $parent = $candidate->firstParent();
            $candidate = $parent === null ? null : ($commits[$parent] ?? null);
        }

        return null;
    }
}
