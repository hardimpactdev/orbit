<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteRemovalProjector;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Routes\NativeClusterRouterReplacementProjector;
use App\Infrastructure\Routes\NativeCustomProxyRouteProjector;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Infrastructure\Routes\NativeRouteRemovalProjector;
use Illuminate\Support\ServiceProvider;

final class RoutesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        RouteDomainProjector::class => NativeDevelopmentRouteProjector::class,
        ClusterRouterReplacementProjector::class => NativeClusterRouterReplacementProjector::class,
        RouteRemovalProjector::class => NativeRouteRemovalProjector::class,
        CustomProxyRouteProjector::class => NativeCustomProxyRouteProjector::class,
        PublicRouteEdgeProjector::class => NativePublicRouteEdgeProjector::class,
    ];

    public function register(): void
    {
        $this->app->tag([RouteCaddySiteSource::class], NodeCaddySiteSource::class);
    }
}
