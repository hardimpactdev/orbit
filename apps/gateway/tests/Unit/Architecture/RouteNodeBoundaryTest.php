<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Instances\NativeProductionRouteProjector;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Routes\NativeClusterRouterReplacementProjector;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Infrastructure\Ssh\SshExecutor;

arch('Route projectors run Node behavior only through named agent commands')
    ->expect([
        NativeDevelopmentRouteProjector::class,
        NativeProductionRouteProjector::class,
        NativeClusterRouterReplacementProjector::class,
        NativePublicRouteEdgeProjector::class,
    ])
    ->not->toUse([DevelopmentSshExecutor::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
