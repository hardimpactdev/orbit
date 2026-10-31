<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class SyncGitHubAppAction
{
    public function __construct(private GitHubAppStore $store, private GatewayServingHost $gateway, private StartOperationAction $start) {}

    public function handle(?string $requestId = null): Operation
    {
        $app = $this->store->credentials();
        $nodeId = $this->gateway->nodeId();
        if ($app === null || $nodeId === null) {
            throw new ResourceOperationException('github.app_missing', 'The Gateway has no registered GitHub App.', 404);
        }

        return $this->start->execute('github.app_sync', 'node', $nodeId, $nodeId, [
            'app_id' => $app->appId, 'app_token' => ['$secret' => 'github.app.jwt', 'id' => $nodeId],
        ], 'github.app.sync', $requestId);
    }
}
