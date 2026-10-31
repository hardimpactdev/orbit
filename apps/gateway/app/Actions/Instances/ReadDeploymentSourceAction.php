<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\DeploymentSourceCheckData;
use App\Data\Instances\DeploymentSourceChecksData;
use App\Data\Instances\DeploymentSourceCommitData;
use App\Data\Instances\DeploymentSourceCommitsData;
use App\Data\Instances\DeploymentSourceHeadData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Models\Instance;

final readonly class ReadDeploymentSourceAction
{
    private const int CandidateLimit = 20;

    public function __construct(private RemoteBranchHeads $heads, private GitHubAppStore $store, private GitHubApi $github) {}

    public function head(Instance $instance): DeploymentSourceHeadData
    {
        return new DeploymentSourceHeadData($this->heads->head($instance->project, $this->branch($instance)));
    }

    public function commits(Instance $instance, string $base): DeploymentSourceCommitsData
    {
        [$repository, $credentials, $installation] = $this->access($instance);
        try {
            $token = $this->github->repositoryReadToken($credentials, $installation, $repository);
            $rows = $this->github->branchCommits($token, $repository, $this->branch($instance));
            $candidate = $rows[0] ?? throw new GitHubApiException('The deployment branch has no commits.');
            $head = $candidate->sha;
            $bySha = [];
            foreach ($rows as $row) {
                $bySha[$row->sha] ??= $row;
            }
            $commits = [];
            $reachedBase = $candidate->sha === $base;
            $truncated = false;
            while ($candidate instanceof GitHubCommit && $candidate->sha !== $base && count($commits) < self::CandidateLimit) {
                $descends = $this->github->compareCommits($token, $repository, $base, $candidate->sha)->headDescendsFromBase();
                $commits[] = new DeploymentSourceCommitData($candidate->sha, $candidate->parents, $descends);
                $parent = $candidate->firstParent();
                if ($parent === $base) {
                    $reachedBase = true;
                    break;
                }
                if ($parent !== null && ! isset($bySha[$parent])) {
                    $truncated = true;
                    break;
                }
                $candidate = $parent === null ? null : $bySha[$parent];
            }

            return new DeploymentSourceCommitsData($head, $commits, $reachedBase, $truncated || (! $reachedBase && $candidate instanceof GitHubCommit && count($commits) === self::CandidateLimit));
        } catch (GitHubApiException $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function checks(Instance $instance, string $commit): DeploymentSourceChecksData
    {
        [$repository, $credentials, $installation] = $this->access($instance);
        $gate = $instance->project->mergeCheckName();
        if ($gate === null) {
            return new DeploymentSourceChecksData(null, []);
        }
        try {
            $token = $this->github->repositoryChecksToken($credentials, $installation, $repository);
            $runs = $this->github->checkRuns($token, $repository, $commit, $gate);

            return new DeploymentSourceChecksData($gate, array_map(
                static fn (GitHubCheckRun $run): DeploymentSourceCheckData => new DeploymentSourceCheckData($run->name, $run->headSha, $run->appSlug, $run->status, $run->conclusion), $runs,
            ));
        } catch (GitHubApiException $exception) {
            throw $this->unavailable($exception);
        }
    }

    /** @return array{GitHubRepository, GitHubAppCredentials, int} */
    private function access(Instance $instance): array
    {
        $repository = GitHubRepository::fromOrigin($instance->project->repository_url);
        if ($instance->project->source_access !== ProjectSourceAccess::GitHubApp || ! $repository instanceof GitHubRepository) {
            throw new ResourceOperationException('instance.auto_deploy_gate_unreadable', 'The Project must use GitHub App access for source evidence.', 409);
        }
        try {
            $credentials = $this->store->credentials() ?? throw new GitHubApiException('The Gateway GitHub App is not registered.');
            $installation = $this->github->repositoryInstallation($credentials, $repository) ?? throw new GitHubApiException('The repository has no GitHub App installation.');

            return [$repository, $credentials, $installation];
        } catch (GitHubApiException $exception) {
            throw $this->unavailable($exception);
        }
    }

    private function branch(Instance $instance): string
    {
        return $instance->placedOnAppDev() ? (string) $instance->project->default_branch : ($instance->deployment_branch ?? (string) $instance->branch);
    }

    private function unavailable(GitHubApiException $exception): ResourceOperationException
    {
        report($exception);

        return new ResourceOperationException('instance.auto_deploy_gate_unavailable', 'The Gateway could not read deployment source evidence.', 502);
    }
}
