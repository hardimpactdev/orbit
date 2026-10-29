<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\PhpPoolInputSource;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteEnvironmentOperationLock;
use App\Domain\Routes\RouteRemovalProjector;
use App\Http\Controllers\Api\OperationExecutorController;
use App\Http\Middleware\Routes\SerializeRouteEnvironment;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Routes\NativeClusterRouterReplacementProjector;
use App\Infrastructure\Routes\NativeCustomProxyRouteProjector;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Infrastructure\Routes\NativeRouteRemovalProjector;
use Illuminate\Routing\Router;
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
        $this->app->extend(InstanceEnvironmentOperationLock::class, static fn (InstanceEnvironmentOperationLock $locks): InstanceEnvironmentOperationLock => new RouteEnvironmentOperationLock($locks));
        $this->app->tag([PhpPoolInputSource::class], OperationSecretSource::class);
        $this->app->tag([RouteCaddySiteSource::class], NodeCaddySiteSource::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
                if ($route->getActionName() === OperationExecutorController::class.'@run') {
                    $route->middleware(SerializeRouteEnvironment::class);
                }
            }
        });
    }
}
