<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\Projects\ProjectGitHubSource;
use App\Models\Instance;

/**
 * Whether a release's commit is already behind the commit that a container default last deployed, read from GitHub before the
 * admission writes anything (docs/reference/deployments.md#development-defaults). An unreadable answer is no answer: the deployment
 * then runs, and its checkout keeps the newer commit as the backstop.
 */
final readonly class ReleaseDeployBehind
{
    public function __construct(private ProjectGitHubSource $source, private GitHubApi $github) {}

    /** The recorded commit that descends strictly from `$target`, or null when the Gateway cannot tell. */
    public function keptCommit(Instance $instance, string $target): ?string
    {
        $recorded = $instance->runtime()->value('seed_commit');
        if (! is_string($recorded) || $recorded === $target) {
            return null;
        }
        try {
            $access = $this->source->access($instance->project);
            if ($access === null) {
                return null;
            }
            [$repository, $credentials, $installation] = $access;
            $token = $this->github->repositoryReadToken($credentials, $installation, $repository);

            return $this->github->compareCommits($token, $repository, $target, $recorded)->headDescendsFromBase() ? $recorded : null;
        } catch (GitHubApiException $exception) {
            report($exception);

            return null;
        }
    }
}
