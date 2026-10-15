<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Project;
use Throwable;

/**
 * Runs `git ls-remote` for one branch on the Gateway, with the Project's read credential in the command's environment
 * ([credentialed Git](/reference/instance-setup#credentialed-git)). It changes nothing. A failed read drops the
 * repository's cached GitHub credentials, so the next read mints fresh ones.
 */
final readonly class GitRemoteBranchHeads implements RemoteBranchHeads
{
    public function __construct(
        private ProcessRunner $processes,
        private RepositoryReadAccess $access,
        private ?GitHubTokenCache $tokens = null,
    ) {}

    public function head(Project $project, string $branch): string
    {
        try {
            $ref = 'refs/heads/'.GitBranchName::validate($branch);
            $result = $this->processes->run(new ProcessInvocation(
                arguments: ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', '/', 'ls-remote', '--exit-code', '--heads', '--', $project->repository_url, $ref],
                timeout: 60.0,
                maxOutputBytes: 65_536,
                environment: $this->access->for($project->repository_url, $project->source_access)->variables,
            ));
        } catch (Throwable $exception) {
            throw $this->failure($project, $branch, $exception);
        }

        if (! $result->succeeded() || $result->truncated
            || preg_match('/\A([0-9a-f]{40}(?:[0-9a-f]{24})?)\t'.preg_quote($ref, '/').'\n?\z/', $result->stdout, $matches) !== 1) {
            throw $this->failure($project, $branch);
        }

        return $matches[1];
    }

    private function failure(Project $project, string $branch, ?Throwable $previous = null): ResourceOperationException
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);

        if ($repository instanceof GitHubRepository) {
            $this->tokens?->forget($repository);
        }

        return new ResourceOperationException(
            'source.head_unavailable',
            "The Gateway could not read the head of the branch [{$branch}] of Project [{$project->slug}].",
            502,
            $previous,
        );
    }
}
