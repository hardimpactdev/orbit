<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Models\Operation;

final readonly class IssueDirectGitHubTokenAction
{
    public function __construct(private DirectOperation $direct, private GitHubAppStore $store, private HttpGitHubApi $github) {}

    /** @return array{grant: GitHubTokenGrant, credential: GitHubAccessToken} */
    public function handle(Operation $operation): array
    {
        $grant = $this->direct->githubTokenGrant($operation);
        if ($operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'This intent no longer permits a GitHub credential.', 409);
        }
        $credentials = $this->store->credentials();
        if ($credentials === null) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'Repository credential unavailable.', 403);
        }
        try {
            return ['grant' => $grant, 'credential' => $this->github->scopedRepositoryToken($credentials, $grant)];
        } catch (ResourceOperationException $exception) {
            $status = $exception->status;
            throw new ResourceOperationException($status === 403 ? 'operation.github_token_forbidden' : 'operation.github_token_unavailable', 'Repository credential unavailable.', $status);
        }
    }
}
