<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\GitHub\DependencyReadToken;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Models\Project;

final readonly class IssueDependencyReadTokenAction
{
    public function __construct(private GitHubAppStore $store, private HttpGitHubApi $github) {}

    /** @return array{project: Project, repository: GitHubRepository, credential: DependencyReadToken} */
    public function handle(int $id): array
    {
        $project = Project::query()->find($id);
        if (! $project instanceof Project) {
            throw new ResourceOperationException('project.not_found', 'Project not found.', 404);
        }
        $repository = GitHubRepository::fromOrigin($project->repository_url ?? '');
        if (! $repository instanceof GitHubRepository || $project->source_access !== ProjectSourceAccess::GitHubApp) {
            throw new ResourceOperationException('dependency_token.repository_unavailable', 'Project requires a GitHub App repository.', 422);
        }
        $credentials = $this->store->credentials();
        if (! $credentials instanceof GitHubAppCredentials) {
            throw new ResourceOperationException('dependency_token.refused', 'Repository installation unavailable.', 403);
        }

        return ['project' => $project, 'repository' => $repository, 'credential' => $this->github->dependencyReadToken($credentials, $repository)];
    }
}
