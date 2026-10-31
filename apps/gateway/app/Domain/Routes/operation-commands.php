<?php

declare(strict_types=1);

use App\Domain\Routes\RouteNodeEffectCommand;
use App\Domain\Routes\RouteParentCommand;
use App\Domain\Routes\RouteWorkloadCommand;

return [
    'route.parent' => RouteParentCommand::class,
    'route.node-effect' => RouteNodeEffectCommand::class,
    'route.workload' => RouteWorkloadCommand::class,
];
