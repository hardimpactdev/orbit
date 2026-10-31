<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubAppJwt;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\Request;

/** NEW worker credential type: short-lived App-wide JWT, only for the admitted Gateway-Node sync. */
final readonly class GitHubAppJwtSource implements OperationSecretSource
{
    public function __construct(private GitHubAppStore $store, private GatewayServingHost $gateway, private DirectOperation $direct, private Request $request) {}

    public function name(): string
    {
        return 'github.app.jwt';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'github.app_sync' && $operation->node_id === $operation->target_id
            && $operation->node_id === $this->gateway->nodeId() ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $caller = ($this->request->getUserResolver())();
        $credentials = $this->store->credentials();
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== 'app_token'
            || ! $caller instanceof Node || $caller->id !== $id || $operation->status->terminal() || $operation->cancel_requested_at !== null
            || $credentials === null || $credentials->appId !== ($operation->input['app_id'] ?? null)) {
            throw new ResourceOperationException('github.app_token_forbidden', 'This intent does not permit an App credential.', 403);
        }
        $this->direct->assertRun($operation, ValidatedData::string($this->request->query('run_id')));
        $now = now()->getTimestamp();
        try {
            $token = GitHubAppJwt::sign($credentials, $now);
        } catch (GitHubApiException) {
            throw new ResourceOperationException('github.app_token_unavailable', 'The App credential could not be minted.', 503);
        }

        return new OperationSecretValue(['app_id' => $credentials->appId, 'token' => $token, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 540)]);
    }
}
