<?php

declare(strict_types=1);
use App\Domain\Routes\NativeClusterRouterProjection;

return [
    'cluster.router_set' => ['workflow' => 'operation-cluster-router', 'projection' => NativeClusterRouterProjection::class],
    'cluster.router_unset' => ['workflow' => 'operation-cluster-router', 'projection' => NativeClusterRouterProjection::class],
];
