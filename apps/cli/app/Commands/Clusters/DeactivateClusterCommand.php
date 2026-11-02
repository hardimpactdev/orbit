<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\DeactivateClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class DeactivateClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:deactivate
        {cluster : Numeric Cluster ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Deactivate a Cluster: it stops serving its private domains and LAN ingress.';

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

        $cluster = $this->sendWithProgress($connector, new DeactivateClusterRequest($clusterId), ClusterResponse::class, ['Deactivate Cluster', 'Deactivating Cluster', 'Deactivated Cluster']);

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Cluster [{$cluster->name}] is inactive.");
    }
}
