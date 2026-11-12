<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\GitHub\RepositoryReadContext;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\ReadDeadline;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\GitHub\CachedGitHubApi;
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

    /** A reader whose GitHub App reads keep the repository's installation and read token in {@see GitHubTokenCache}. */
    public static function withCachedCredentials(ProcessRunner $processes, GitHubAppStore $store, CachedGitHubApi $github, GitHubCliToken $cli, GitHubTokenCache $tokens): self
    {
        return new self($processes, new RepositoryReadAccess($store, $github, $cli), $tokens);
    }

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

    /** @return list<string> All remote heads, bounded by a ten-second read and 4 MiB of output. */
    public function names(Project $project, ?RepositoryReadContext $context = null): array
    {
        return array_map(strval(...), array_keys($this->heads($project, $context)));
    }

    /** @return array<string,string> Remote branch names and observed SHAs, within the same credentialed ten-second budget. */
    public function heads(Project $project, ?RepositoryReadContext $context = null): array
    {
        $deadline = new ReadDeadline(10.0);
        try {
            $environment = $this->access->for($project->repository_url, $project->source_access, $deadline, $context);
            if ($context !== null) {
                $context->environment = $environment;
            }
            $result = $this->processes->run(new ProcessInvocation(
                arguments: ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', '/', 'ls-remote', '--heads', '--', $project->repository_url],
                timeout: $deadline->remaining(),
                maxOutputBytes: 4_194_304,
                environment: $environment->variables,
            ));
            $deadline->remaining();
            if (! $result->succeeded() || $result->truncated) {
                throw new \UnexpectedValueException;
            }
            $heads = [];
            foreach (explode("\n", rtrim($result->stdout, "\n")) as $line) {
                if ($line === '' && $result->stdout === '') {
                    continue;
                }
                if (preg_match('/\A([0-9a-f]{40}(?:[0-9a-f]{24})?)\trefs\/heads\/(.+)\z/D', $line, $matches) !== 1 || ! GitBranchName::isValidSyntax($matches[2])) {
                    throw new \UnexpectedValueException;
                }
                $heads[$matches[2]] = $matches[1];
            }

            $deadline->remaining();

            return $heads;
        } catch (Throwable $exception) {
            throw $this->failure($project, null, $exception);
        }
    }

    private function failure(Project $project, ?string $branch, ?Throwable $previous = null): ResourceOperationException
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);

        if ($repository instanceof GitHubRepository) {
            $this->tokens?->forget($repository);
        }

        return new ResourceOperationException(
            'source.head_unavailable',
            $branch === null ? "The Gateway could not read the remote branches of Project [{$project->slug}]."
                : "The Gateway could not read the head of the branch [{$branch}] of Project [{$project->slug}].",
            502,
            $previous,
        );
    }
}
