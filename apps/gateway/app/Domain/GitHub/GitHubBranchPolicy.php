<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Shared\ResourceOperationException;

final readonly class GitHubBranchPolicy
{
    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function assertNonStrict(string $origin, string $branch): void
    {
        try {
            $repository = GitHubRepository::fromOrigin($origin)
                ?? throw GitHubApiException::unavailable();
            $rules = $this->github->rulesRequireUpToDate($this->access->metadataToken($repository), $repository, $branch);
            $protection = $this->github->protectionRequiresUpToDate($this->access->administrationReadToken($repository), $repository, $branch);
        } catch (GitHubApiException $exception) {
            throw new ResourceOperationException('project.branch_policy_unavailable', 'Orbit could not read both branch-rule sources. Accept Administration read on the App installation and retry.', 503, previous: $exception);
        }
        if ($rules || $protection) {
            throw new ResourceOperationException('project.strict_base', 'Review and merge requires a base branch without an up-to-date-branch requirement.', 422);
        }
    }
}
