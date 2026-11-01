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
        $repository = GitHubRepository::fromOrigin($authority['repository']);
        $credentials = $this->store->credentials();
        $credential = $repository !== null && $credentials !== null && $this->github->repositoryInstallation($credentials, $repository) !== null
            ? $this->github->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $repository->owner.'/'.$repository->name, 'permissions' => ['contents' => 'read']]))
            : null;
        try {
            if ($this->authorize($operation, $callerNodeId, $runId) !== $authority) {
                throw new ResourceOperationException('operation.github_token_forbidden', 'The admitted repository changed during minting.', 409);
            }
            $reference = self::reference($operation);

            return ['git_read' => $this->step->executionCopy($reference['vault'], $reference['key'], json_encode(['github_token' => $credential?->reveal()], JSON_THROW_ON_ERROR))];
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
        $input = $operation->input;
        $project = Project::query()->find($input['project_id'] ?? null);
        $instance = Instance::query()->find($operation->target_id);
        if ($callerNodeId !== 209 || $operation->target_type !== 'instance' || $operation->status->terminal()
            || $operation->cancel_requested_at !== null || ! $project instanceof Project || ! $instance instanceof Instance
            || $instance->id !== ($input['instance_id'] ?? null) || $instance->project_id !== $project->id
            || ! $instance->placedOnAppDev() || $instance->node_id !== $operation->node_id || ($input['repository'] ?? null) !== $project->repository_url
            || ($input['repository_identity'] ?? null) !== $project->repository_identity
            || GitRepositoryIdentity::derive($project->repository_url) !== $project->repository_identity
            || $project->source_access === ProjectSourceAccess::GhCli) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'Only the admitted Instance repository can be read with scoped App access.', 403);
        }

        return ['kind' => $operation->kind, 'repository' => $project->repository_url,
            'repository_identity' => $project->repository_identity, 'source_access' => $project->source_access->value];
    }
}
