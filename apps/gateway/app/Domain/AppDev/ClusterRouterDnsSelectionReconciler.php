<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Clusters\ClusterState;

/** Holds a Cluster change in its rows while its private DNS is published, then releases it. */
interface ClusterRouterDnsSelectionReconciler
{
    /**
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function expand(array $clusterOverrides): void;

    /**
     * @param  list<int>  $clusterIds
     */
    public function prune(array $clusterIds): void;
}
