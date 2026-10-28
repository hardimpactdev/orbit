<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Infrastructure\Nodes\AgentManagedUserAccountResolver;
use App\Infrastructure\Nodes\AgentNodeStorageRootPreparer;
use App\Infrastructure\Nodes\EloquentNodeRoleDependencyInspector;
use App\Infrastructure\Nodes\Metrics\GrafanaPrometheusNodeMetricsReader;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Infrastructure\Nodes\NativeNodeRoleDependentCleaner;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\Roles\GatewayRoleBaseline;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Nodes\Roles\NativeRoleBaselineConverger;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Infrastructure\Nodes\SshNodeReachabilityProbe;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class NodesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        NodeConverger::class => NativeNodeConverger::class,
        NodeStorageRootPreparer::class => AgentNodeStorageRootPreparer::class,
        NodeReachabilityProbe::class => SshNodeReachabilityProbe::class,
        NodeMetricsReader::class => GrafanaPrometheusNodeMetricsReader::class,
        NodeRoleDependencyInspector::class => EloquentNodeRoleDependencyInspector::class,
        NodeRoleDependentCleaner::class => NativeNodeRoleDependentCleaner::class,
        NodeRoleFirewallManager::class => NativeNodeRoleFirewallManager::class,
        RoleBaselineConverger::class => NativeRoleBaselineConverger::class,
        GatewayPrivateDnsRoute::class => GatewayRoleBaseline::class,
        NodeAgentRuntime::class => NodeAgentSshExecutor::class,
    ];

    public function register(): void
    {
        $this->app->singleton(
            NodeLocks::class,
            static fn ($app): NodeLocks => new NodeLocks(
                $app->make(CacheManager::class)->build(NodeLocks::storeConfiguration(Config::string('orbit.home'))),
                console: $app->runningInConsole(),
            ),
        );
        $this->app->singleton(NodeRoleConvergeLock::class);
        $this->app->singleton(ManagedUserAccountResolver::class, AgentManagedUserAccountResolver::class);
        $this->app->scoped(NodeProvisioningLock::class, NativeNodeProvisioningLock::class);
        $this->app->scoped(NodeRoleFollowUpReport::class);
    }
}
