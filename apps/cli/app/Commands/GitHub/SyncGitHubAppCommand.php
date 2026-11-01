<?php

declare(strict_types=1);

namespace App\Commands\GitHub;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GitHub\SyncGitHubAppRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class SyncGitHubAppCommand extends GitHubCommand
{
    #[\Override]
    protected $signature = 'github:app:sync
        {--idempotency-key= : Reuse this key for the same refresh request}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record a native workflow request to refresh the GitHub App installations.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $key = $this->option('idempotency-key');
        $operation = $this->sendWithProgress($connector, new SyncGitHubAppRequest(is_string($key) ? $key : null),
            OperationResponse::class, ['Refresh GitHub App', 'Recording refresh intent', 'Recorded refresh intent']);
        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($operation->toArray());
        } else {
            $this->writeHumanMessage('Operation '.$operation->id.': '.$operation->status.'.');
            $this->writeHumanMessage('Follow it with: orbit operation:show '.$operation->id);
            $this->writeHumanMessage('Request ID: '.$operation->requestId);
        }

        return self::SUCCESS;
    }
}
