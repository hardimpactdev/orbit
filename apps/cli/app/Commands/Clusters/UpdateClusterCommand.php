<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\UpdateClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class UpdateClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:update
        {cluster : Numeric Cluster ID}
        {--name= : New Cluster name}
        {--tld= : New development TLD; empty unsets it}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();

        if ($clusterId === null) {
            return self::FAILURE;
        }

        $name = $this->input->getOption('name');
        $tld = $this->input->getOption('tld');
        $hasName = $name !== null;
        $hasTld = $tld !== null;

        if (! $hasName && ! $hasTld) {
            return $this->renderGatewayFailure(
                'cluster.update_required',
                'Provide at least one Cluster update option.',
            );
        }

        if ($hasName && (! is_string($name) || $name === '')) {
            return $this->renderGatewayFailure('cluster.name_invalid', 'Cluster name cannot be empty.');
        }

        if ($hasTld && ! is_string($tld)) {
            return $this->renderGatewayFailure('cluster.tld_invalid', 'TLD must be one DNS label.');
        }

        if (is_string($tld) && $tld !== '' && ! $this->validTld($tld)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->sendWithProgress(
            $connector,
            new UpdateClusterRequest(
                clusterId: $clusterId,
                hasName: $hasName,
                name: is_string($name) ? $name : null,
                hasTld: $hasTld,
                tld: is_string($tld) && $tld !== '' ? $tld : null,
            ),
            ClusterResponse::class,
            ['Update Cluster', 'Updating Cluster', 'Updated Cluster'],
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Cluster [{$cluster->name}] updated.");
    }
}
