<?php

declare(strict_types=1);

use App\Domain\Routes\AnalyticsRouteParentCommand;
use App\Domain\Routes\ClusterRouterParentCommand;
use App\Domain\Routes\InstanceRuntimeCommand;
use App\Domain\Routes\OfflineResidueCommand;
use App\Domain\Routes\RouteNodeEffectCommand;
use App\Domain\Routes\RouteParentCommand;
use App\Domain\Routes\RouteWorkloadCommand;

return [
    'route.cluster-router' => ClusterRouterParentCommand::class,
    'route.parent' => RouteParentCommand::class,
    'route.analytics-parent' => AnalyticsRouteParentCommand::class,
    'route.node-effect' => RouteNodeEffectCommand::class,
    'route.workload' => RouteWorkloadCommand::class,
    'route.offline-residue' => OfflineResidueCommand::class,
    'route.instance-runtime' => InstanceRuntimeCommand::class,
];
