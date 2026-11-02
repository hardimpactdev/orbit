<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;

/**
 * Safe admitted GitHub intent and bounded native outcome. At run start the Gateway mints the one scoped
 * repository token the run needs and puts it in the vault; the run revokes it at the end.
 */
final readonly class RecipePrProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** Never `workflows`: a recipe PR changes only the recipe file. */
    public const array Grant = ['repository' => 'hardimpactdev/orbit', 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'checks' => 'read']];

    public function input(Operation $operation): array
    {
        return ['intent' => array_intersect_key($operation->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha', 'author', 'required_check', 'deployed_recipe']))];
    }

    public function workflowInput(Operation $operation): array
    {
        $credentials = app(GitHubAppStore::class)->credentials();
        if ($credentials === null) {
            throw new ResourceOperationException('operation.github_token_unavailable', 'Repository installation unavailable.', 503);
        }
        $github = app(HttpGitHubApi::class);
        $token = $github->scopedRepositoryToken($credentials, GitHubTokenGrant::from(self::Grant));
        try {
            $copy = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'fleet-recipe-pr-'.$operation->id, $token->reveal());
        } catch (\Throwable $exception) {
            if (! $github->revokeInstallationToken($token)) {
                Log::warning('operation.github_token_revoke_failed');
            }
            throw $exception;
        }

        return [...$this->input($operation), 'execution_secrets' => ['github_token' => $copy]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['completed', 'outcome', 'url', 'error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
