<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Infrastructure\Clusters\NativeClusterRouterOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class ClustersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            ClusterRouterOperationLock::class,
            static fn (): ClusterRouterOperationLock => new NativeClusterRouterOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/cluster-router',
                deadline: app(CommandDeadline::class),
            ),
        );
    }
}
