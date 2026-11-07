<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Clusters\ClusterState;
use App\Models\Cluster;

final readonly class NativeClusterRouterDnsSelectionReconciler implements ClusterRouterDnsSelectionReconciler
{
    public function __construct(private PrivateDnsManager $dns) {}

    /**
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function expand(array $clusterOverrides): void
    {
        foreach ($clusterOverrides as $clusterId => $pending) {
            Cluster::query()->findOrFail($clusterId)->holdDns($pending);
        }

        $this->dns->converge();
    }

    /**
     * @param  list<int>  $clusterIds
     */
    public function prune(array $clusterIds): void
    {
        foreach (Cluster::query()->whereKey($clusterIds)->get() as $cluster) {
            $cluster->releaseDns();
        }

        $this->dns->converge();
    }
}
