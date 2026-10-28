<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\SetClusterRouterRequest;
use App\Commands\Concerns\FollowsOperations;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class SetClusterRouterCommand extends ClusterCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'cluster:router:set
        {cluster : Numeric Cluster ID}
        {node : Numeric Node ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Set or replace a Cluster Router.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();
        if ($clusterId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->nodeId();
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new SetClusterRouterRequest($clusterId, $nodeId, $this->stringOption('idempotency-key')), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Set Cluster Router', 'Cluster Router')
            : self::FAILURE;
    }
}
