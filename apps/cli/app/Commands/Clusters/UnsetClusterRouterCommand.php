<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use App\Commands\Concerns\FollowsOperations;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UnsetClusterRouterCommand extends ClusterCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'cluster:router:unset
        {cluster : Numeric Cluster ID}
        {--force : Skip the destructive confirmation prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clear the Router from an inactive Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();

        if ($clusterId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $existing = $this->existingCluster($connector, $clusterId);

        if ($existing === null || ! $this->confirmed('Router clearing', $existing)) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new UnsetClusterRouterRequest($clusterId, true, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Clear Cluster Router', 'Cluster Router')
            : self::FAILURE;
    }
}
