<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\ActivateClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class ActivateClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:activate
        {cluster : Numeric Cluster ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Activate a Cluster: it serves its private domains and LAN ingress.';

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

        $cluster = $this->sendWithProgress($connector, new ActivateClusterRequest($clusterId), ClusterResponse::class, ['Activate Cluster', 'Activating Cluster', 'Activated Cluster']);

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Cluster [{$cluster->name}] is active.");
    }
}
