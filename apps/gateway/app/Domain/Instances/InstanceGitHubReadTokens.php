<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\Operations\DirectOperation;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;

/** Owner-checked read scope at the existing numeric Operation mint primitive. */
final readonly class InstanceGitHubReadTokens implements NativeGitHubTokenIssuer
{
    public function __construct(private DirectOperation $direct, private GitHubAppStore $store, private HttpGitHubApi $github, private SwampCommandStep $step) {}

    /** @return array{vault:string,key:string} */
    public static function reference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-git-operation-'.$operation->id];
    }

    public function issue(Operation $operation, int $callerNodeId, string $runId): array
    {
        $authority = $this->authorize($operation, $callerNodeId, $runId);

        return ['git_read' => $this->copy($operation, $authority['repository'],
            fn () => $this->authorize($operation, $callerNodeId, $runId) === $authority)];
    }

    /**
     * Development create mints when its run starts, as the legacy Gateway did before its source call.
     * An already active create runs no source phase and gets no credential.
     *
     * @return array{vault:string,key:string}
     */
    public function start(Operation $operation): array
    {
        if ((ValidatedData::object($operation->input['direct_provisioning'] ?? [])['already_active'] ?? false) === true) {
            $reference = self::reference($operation);

            return $this->step->executionCopy($reference['vault'], $reference['key'], '{"github_token":null}');
        }
        $authority = $this->repository($operation);

        return $this->copy($operation, $authority['repository'], fn (): bool => $this->repository($operation->refresh()) === $authority);
    }

    /**
     * @param  \Closure(): bool  $unchanged
     * @return array{vault:string,key:string}
     */
    private function copy(Operation $operation, string $origin, \Closure $unchanged): array
    {
        $repository = GitHubRepository::fromOrigin($origin);
        $credentials = $this->store->credentials();
        $credential = $repository !== null && $credentials !== null && $this->github->repositoryInstallation($credentials, $repository) !== null
            ? $this->github->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $repository->owner.'/'.$repository->name, 'permissions' => ['contents' => 'read']]))
            : null;
        try {
            if (! $unchanged()) {
                throw new ResourceOperationException('operation.github_token_forbidden', 'The admitted repository changed during minting.', 409);
            }
            $reference = self::reference($operation);

            return $this->step->executionCopy($reference['vault'], $reference['key'], json_encode(['github_token' => $credential?->reveal()], JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            if ($credential !== null && ! $this->github->revokeInstallationToken($credential)) {
                Log::warning('operation.github_token_revoke_failed');
            }
            throw $exception;
        }
    }

    /** @return array{kind:string,repository:string,repository_identity:string,source_access:string} */
    private function authorize(Operation $operation, int $callerNodeId, string $runId): array
    {
        $operation->refresh();
        $this->direct->assertRun($operation, $runId);
        if ($callerNodeId !== 209 || $operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw $this->forbidden();
        }

        return $this->repository($operation);
    }

    /** @return array{kind:string,repository:string,repository_identity:string,source_access:string} */
    private function repository(Operation $operation): array
    {
        $input = $operation->input;
        $project = Project::query()->find($input['project_id'] ?? null);
        $instance = Instance::query()->find($operation->target_id);
        if ($operation->target_type !== 'instance' || ! $project instanceof Project || ! $instance instanceof Instance
            || $instance->id !== ($input['instance_id'] ?? null) || $instance->project_id !== $project->id
            || ! $instance->placedOnAppDev() || $instance->node_id !== $operation->node_id || ($input['repository'] ?? null) !== $project->repository_url
            || ($input['repository_identity'] ?? null) !== $project->repository_identity
            || GitRepositoryIdentity::derive($project->repository_url) !== $project->repository_identity
            || $project->source_access === ProjectSourceAccess::GhCli) {
            throw $this->forbidden();
        }

        return ['kind' => $operation->kind, 'repository' => $project->repository_url,
            'repository_identity' => $project->repository_identity, 'source_access' => $project->source_access->value];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.github_token_forbidden', 'Only the admitted Instance repository can be read with scoped App access.', 403);
    }
}
