<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Jobs\Operations\RunGitHubAppOperation;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class SyncGitHubAppAction
{
    public function __construct(private GitHubAppStore $store, private GatewayServingHost $gateway, private OperationStore $operations, private OperationIdempotency $idempotency) {}

    public function handle(?string $requestId = null): Operation
    {
        $app = $this->store->credentials();
        $nodeId = $this->gateway->nodeId();
        if ($app === null || $nodeId === null) {
            throw new ResourceOperationException('github.app_missing', 'The Gateway has no registered GitHub App.', 404);
        }

        if (($existing = $this->idempotency->replay('github.app_sync')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($app, $nodeId, $requestId): Operation {
            $operation = $this->operations->create('github.app_sync', 'node', $nodeId, $nodeId, ['app_id' => $app->appId], 'github.app.sync', $requestId);
            if ($operation->wasRecentlyCreated) {
                DB::afterCommit(static fn () => RunGitHubAppOperation::dispatch($operation->id));
            }

            return $operation;
        });
    }
}
