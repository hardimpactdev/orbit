<?php

declare(strict_types=1);
use App\Domain\Routes\NativeClusterRouterProjection;

return [
    // The end check is the first workload probe from the candidate; a Cluster without Routes ends at its preparation.
    'cluster.router_set' => ['workflow' => 'operation-cluster-router', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'router', 'step' => 'Probe the workloads-0', 'alternatives' => ['Prepare the Router-0'], 'native' => true],
        'projection' => NativeClusterRouterProjection::class],
    // The end check is the first Node's cfgmgmt check that the Router's sites and rules are gone.
    'cluster.router_unset' => ['workflow' => 'operation-cluster-router-unset', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'router', 'step' => 'Check the rules are gone-0', 'native' => true], 'projection' => NativeClusterRouterProjection::class],
];
