<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use Throwable;

/**
 * Checks a clone candidate's source on its Node through the agent's `instance.inspect-clone-candidate`
 * (docs/reference/instance-cloning.md).
 */
final readonly class RemoteInstanceCloneCandidateInspector implements InstanceCloneCandidateInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private RepositoryReadAccess $access,
    ) {}

    public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource
    {
        if (! GitBranchName::isValid($targetBranch)) {
            throw $this->conflict(
                'instance.clone_target_branch_missing',
                'The selected target branch is invalid.',
            );
        }

        $candidate->refresh()->loadMissing(['project', 'node']);
        $node = $candidate->node;
        [$basePath, $executionUser, $configuredBranch, $expectedSource] = $this->identity($candidate, $node);

        try {
            $result = $this->agents->run($node, 'instance.inspect-clone-candidate', [
                'environment' => $candidate->defaultAppEnv(),
                'base' => $basePath,
                'user' => $executionUser,
                'repository' => $candidate->project->repository_url,
                'configured_branch' => $configuredBranch,
                'target_branch' => $targetBranch,
                'expected_source' => $expectedSource,
                'git_read' => $this->access->for($candidate->project->repository_url, $candidate->project->source_access),
            ]);
        } catch (AgentCommandRefused $refusal) {
            $stderr = is_string($refusal->details['stderr'] ?? null) ? self::redacted($refusal->details['stderr']) : '';

            if (str_starts_with($refusal->errorCode, 'instance.clone_')) {
                throw $this->conflict(
                    $refusal->errorCode,
                    'The candidate is not eligible for cloning.'.$this->stderrSuffix($stderr),
                    $stderr,
                );
            }

            throw $this->conflict('instance.clone_candidate_unavailable', 'The candidate source could not be inspected.');
        } catch (Throwable $exception) {
            throw new ResourceOperationException(
                errorCode: 'instance.clone_candidate_unavailable',
                message: 'The candidate source could not be inspected.',
                status: 502,
                previous: $exception,
            );
        }

        $source = $result['source'] ?? null;
        $commit = $result['commit'] ?? null;

        if (! is_string($source) || ! is_string($commit) || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            throw $this->conflict('instance.clone_candidate_unavailable', 'The candidate inspection was invalid.');
        }

        if ($source !== $expectedSource) {
            throw $this->conflict(
                'instance.clone_candidate_source_invalid',
                'The candidate selected source changed during inspection.',
            );
        }

        return new CloneCandidateSource(
            instanceId: $candidate->id,
            environment: $candidate->defaultAppEnv(),
            basePath: $source,
            executionUser: $executionUser,
            branch: $configuredBranch,
            commit: $commit,
            node: $node,
        );
    }

    /** @return array{string, string, string, string} */
    private function identity(Instance $candidate, Node $node): array
    {
        if (
            $candidate->status !== InstanceState::Active
            || $candidate->provisioning_step !== 'active'
            || $node->status !== LifecycleStatus::Active
            || $node->platform !== 'linux'
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
        ) {
            throw $this->conflict('instance.clone_candidate_inactive', 'The candidate is not active.');
        }

        if ($candidate->placedOnAppDev()) {
            $basePath = $candidate->checkout_path;
            $executionUser = $node->user;
            $configuredBranch = $candidate->branch;
            $expectedSource = $candidate->checkout_path;
        } elseif ($candidate->placedOnAppProd()) {
            if (! $candidate->usesProductionReleaseLayout()) {
                throw $this->conflict(
                    'instance.clone_candidate_release_missing',
                    'The production candidate has no selected release.',
                );
            }

            $basePath = $candidate->production_home;
            $executionUser = $candidate->production_user;
            $configuredBranch = $candidate->deployment_branch ?? $candidate->branch;
            $expectedSource = $candidate->checkout_path;
        } else {
            throw $this->conflict(
                'instance.clone_candidate_environment_invalid',
                'Only development or production candidates can be cloned.',
            );
        }

        if (
            ! is_string($basePath)
            || ! str_starts_with($basePath, '/')
            || ! is_string($executionUser)
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $executionUser) !== 1
            || ! is_string($configuredBranch)
            || ! GitBranchName::isValid($configuredBranch)
            || ! str_starts_with($expectedSource, '/')
        ) {
            throw $this->conflict('instance.clone_candidate_source_invalid', 'The candidate source identity is invalid.');
        }

        return [$basePath, $executionUser, $configuredBranch, $expectedSource];
    }

    private function conflict(string $errorCode, string $message, string $stderr = ''): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409, details: $stderr === '' ? [] : ['stderr' => $stderr]);
    }

    /** Keeps the last 1,000 characters, without URL credentials or token-shaped words. */
    private static function redacted(string $stderr): string
    {
        $text = (string) preg_replace(
            ['#(://)[^/\s@]+@#', '/\b(?:gh[opsur]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|[A-Za-z0-9+_-]{40,}={0,2})/'],
            ['$1[REDACTED]@', '[REDACTED]'],
            trim($stderr),
        );

        return mb_substr($text, -1000);
    }

    private function stderrSuffix(string $stderr): string
    {
        return $stderr === '' ? '' : ' '.mb_substr(strtok($stderr, "\n") ?: '', 0, 300);
    }
}
