<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Models\Activity;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

final readonly class IssueDirectGitHubTokenAction
{
    public function __construct(private DirectOperation $direct, private GitHubAppStore $store, private HttpGitHubApi $github, private NodeAccessAuthorizer $nodes) {}

    /** @return array{grant: GitHubTokenGrant, credential: GitHubAccessToken} */
    public function handle(Operation $operation, int $callerNodeId): array
    {
        $operation->refresh();
        $grant = $this->direct->githubTokenGrant($operation);
        $gatewayNodeId = $this->nodes->activeGatewayId();
        $controlPlaneCaller = in_array(config('dependency_detection.services_node_id'), [$callerNodeId, (string) $callerNodeId], true);
        if ($operation->node_id !== $callerNodeId
            && (! $controlPlaneCaller || $gatewayNodeId === null || $operation->node_id !== $gatewayNodeId)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node cannot mint for the Operation Node.', 403);
        }
        if ($operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'This intent no longer permits a GitHub credential.', 409);
        }
        $credentials = $this->store->credentials();
        if ($credentials === null) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'Repository credential unavailable.', 403);
        }
        try {
            $credential = $this->github->scopedRepositoryToken($credentials, $grant);
        } catch (ResourceOperationException $exception) {
            $status = $exception->status;
            throw new ResourceOperationException($status === 403 ? 'operation.github_token_forbidden' : 'operation.github_token_unavailable', 'Repository credential unavailable.', $status);
        }
        Activity::query()->create([
            'request_id' => $operation->request_id ?? (string) Str::uuid(),
            'command' => 'operation:github:mint', 'description' => 'Issued a scoped GitHub installation credential.',
            'subject_type' => Operation::class, 'subject_id' => $operation->id, 'status' => 'succeeded', 'exit_code' => 0,
            'properties' => ['operation_id' => $operation->id, 'run_id' => ValidatedData::string($operation->state['swamp_run_id'] ?? null),
                'caller_node_id' => $callerNodeId, 'repository' => $grant->repository->owner.'/'.$grant->repository->name,
                'permissions' => $grant->permissions, 'expires_at' => $credential->expiresAt],
        ]);

        return ['grant' => $grant, 'credential' => $credential];
    }
}
