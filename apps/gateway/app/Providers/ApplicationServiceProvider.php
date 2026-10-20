<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Actions\Instances\RunAutomaticDeploysAction;
use App\Console\Commands\AgentViewCommand;
use App\Console\Commands\AgentViewPublishCommand;
use App\Console\Commands\AutoDeployCommand;
use App\Console\Commands\BootstrapGatewayCommand;
use App\Console\Commands\CaddyBuildCommand;
use App\Console\Commands\CheckNodeDnsCommand;
use App\Console\Commands\ConvergeGatewayWebCommand;
use App\Console\Commands\ConvergeNodeRecipeCommand;
use App\Console\Commands\FinalizeInterruptedActivitiesCommand;
use App\Console\Commands\MachineRecipeCommand;
use App\Console\Commands\MigrateDockerCommand;
use App\Console\Commands\ProvisionNodeCommand;
use App\Console\Commands\ReconcilePeerDnsCommand;
use App\Console\Commands\RenderNodeProgramsCommand;
use App\Console\Commands\RepairNodeDnsCommand;
use App\Console\Commands\RetargetNodeCommand;
use App\Console\Commands\RuntimeHibernatorCommand;
use App\Console\Commands\ServePrivateDnsCommand;
use App\Domain\AgentView\AgentProcessView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\Analytics\AnalyticsProcessOwnership;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseServers\DatabaseServerProcessOwnership;
use App\Domain\DatabaseServers\ValkeyConnectionUpdateHook;
use App\Domain\Doctor\CaddyBuildInspector;
use App\Domain\Doctor\PhpPoolDirectoryInspector;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\RouteApplicationUrlInspector;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Gateway\GatewayCacheStore;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GreenCommitInspector;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\ProcessAgentViewPublisher;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Analytics\NativeAnalyticsRoleSettingsRepository;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\AppDev\NativeClusterRouterDnsSelectionReconciler;
use App\Infrastructure\AppDev\NativeDevelopmentProjectionOperationLock;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Broadcasting\ReverbBroadcaster;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\Clusters\NativeClusterRouterOperationLock;
use App\Infrastructure\Doctor\NativeCaddyBuildInspector;
use App\Infrastructure\Doctor\NativePhpPoolDirectoryInspector;
use App\Infrastructure\Doctor\NativePrivateRouteProjectionInspector;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Doctor\NativeRouteApplicationUrlInspector;
use App\Infrastructure\Firewall\NativeRouterLanIngressReconciler;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\GitHub\CachedGitHubApi;
use App\Infrastructure\GitHub\GitHubGreenCommitResolver;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
use App\Infrastructure\Hibernation\RemoteInstanceRuntimeReadiness;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\NativeProductionRouteProjector;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Instances\RemoteProductionRepositoryBinding;
use App\Infrastructure\Instances\RemoteProductionWebRootManager;
use App\Infrastructure\Logs\CacheLogStreamStore;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Infrastructure\Metrics\NativeServiceMetricsLifecycle;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\Roles\GatewayRoleBaseline;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Infrastructure\Nodes\SshNodeReachabilityProbe;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\NativeProcessAdmissionLock;
use App\Infrastructure\Processes\NativeProcessRuntimeLease;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\PrometheusProcessRuntimeStatusIndex;
use App\Infrastructure\Routes\NativeClusterRouterReplacementProjector;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Infrastructure\Ssh\GatewaySshKeys;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmCredentialGuard;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Infrastructure\Tools\VpToolManager;
use App\Infrastructure\WebSocket\NativeWebSocketCredentialManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Instance;
use App\Support\ValidatedData;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Most classes declare their own wiring with container attributes (#[Bind], #[Singleton], #[Scoped], #[Config],
 * #[Give]). This provider keeps only what an attribute cannot say:
 *
 * - Abstracts that a constructor declares with a default value. Laravel's container passes that default unless
 *   the abstract is bound, and an attribute binds it only when something first resolves it.
 * - Factories with logic: a built cache store, a path under the Orbit home, closures, or arguments that differ
 *   from the constructor's defaults.
 * - Tags, and the work that must run at boot.
 */
final class ApplicationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceEnvironmentReader::class => RemoteInstanceEnvironmentAccess::class,
        InstanceTestEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        AppDevPhpFpmManager::class => RemoteAppDevPhpFpmManager::class,
        ProductionWebRootManager::class => RemoteProductionWebRootManager::class,
        ProductionRepositoryBinding::class => RemoteProductionRepositoryBinding::class,
        DevelopmentRouteProjector::class => NativeDevelopmentRouteProjector::class,
        ProductionPhpRuntimeManager::class => RemoteProductionPhpRuntimeManager::class,
        ProductionRouteProjector::class => NativeProductionRouteProjector::class,
        ProductionCloneRouteProjector::class => NativeProductionRouteProjector::class,
        ClusterRouterReplacementProjector::class => NativeClusterRouterReplacementProjector::class,
        PublicRouteEdgeProjector::class => NativePublicRouteEdgeProjector::class,
        PublicRouteEdgeInspector::class => NativePublicRouteEdgeInspector::class,
        PrivateRouteProjectionInspector::class => NativePrivateRouteProjectionInspector::class,
        RouteApplicationUrlInspector::class => NativeRouteApplicationUrlInspector::class,
        ServiceMetricsLifecycle::class => NativeServiceMetricsLifecycle::class,
        ServiceMetricsProjection::class => ServiceMetricsProjection::class,
        MetricsFleetReconciler::class => NativeMetricsFleetReconciler::class,
        NodeReachabilityProbe::class => SshNodeReachabilityProbe::class,
        RouterLanIngressReconciler::class => NativeRouterLanIngressReconciler::class,
        GatewayPrivateDnsRoute::class => GatewayRoleBaseline::class,
        ProcessRuntimeStatusIndex::class => PrometheusProcessRuntimeStatusIndex::class,
        AgentStateView::class => CacheAgentStateView::class,
        HibernationMarkerStore::class => RemoteHibernationMarkerStore::class,
        // Moves to #[Bind] after the handoff CI change (#1399), which edits GitHubApi.
        GitHubApi::class => HttpGitHubApi::class,
        SshExecutor::class => TaskVmCredentialGuard::class,
        ClusterRouterDnsSelectionReconciler::class => NativeClusterRouterDnsSelectionReconciler::class,
        CaddyBuildInspector::class => NativeCaddyBuildInspector::class,
        PhpPoolDirectoryInspector::class => NativePhpPoolDirectoryInspector::class,
        AnalyticsRoleSettingsRepository::class => NativeAnalyticsRoleSettingsRepository::class,
        WebSocketCredentialManager::class => NativeWebSocketCredentialManager::class,
    ];

    public function register(): void
    {
        $this->app->tag([ValkeyConnectionUpdateHook::class], DatabaseConnectionUpdateHook::class);

        $this->app->tag([AnalyticsProcessOwnership::class, DatabaseServerProcessOwnership::class], ProcessRemovalGuard::class);

        $this->app->tag([
            GatewayWebCaddySiteSource::class,
            MetricsCaddySiteSource::class,
            ServiceMetricsCaddySiteSource::class,
            RouteCaddySiteSource::class,
            WebSocketCaddySiteSource::class,
            AnalyticsCaddySiteSource::class,
        ], NodeCaddySiteSource::class);

        $this->app->bind(
            SweepIdleAppDevRuntimesAction::class,
            static fn ($app): SweepIdleAppDevRuntimesAction => new SweepIdleAppDevRuntimesAction(
                policy: $app->make(DevelopmentHibernationPolicy::class),
                admissions: $app->make(ProcessAdmissionLock::class),
                runtime: $app->make(ProcessRuntimeManager::class),
                markers: $app->make(HibernationMarkerStore::class),
                checkouts: $app->make(InstanceCheckoutInspector::class),
                idleSeconds: Config::integer('orbit.hibernation.idle_seconds'),
                dependencyIdleSeconds: Config::integer('orbit.hibernation.dependency_idle_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->singleton(
            LogStreamStore::class,
            static fn ($app): CacheLogStreamStore => new CacheLogStreamStore(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->singleton(
            NodeLocks::class,
            static fn ($app): NodeLocks => new NodeLocks(
                $app->make(CacheManager::class)->build(NodeLocks::storeConfiguration(Config::string('orbit.home'))),
                console: $app->runningInConsole(),
            ),
        );
        $this->app->singleton(NodeRoleConvergeLock::class);
        // The automatic deployment tick reads branch heads every minute, so its reads keep their GitHub credentials.
        $this->app->bind(
            RemoteBranchHeads::class,
            static fn ($app): RemoteBranchHeads => GitRemoteBranchHeads::withCachedCredentials(
                $app->make(ProcessRunner::class),
                $app->make(GitHubAppStore::class),
                $app->make(CachedGitHubApi::class),
                $app->make(GitHubCliToken::class),
                $app->make(GitHubTokenCache::class),
            ),
        );
        $this->app->when(RunAutomaticDeploysAction::class)->needs(GreenCommitInspector::class)->give(
            static fn ($app): GreenCommitInspector => new GitHubGreenCommitResolver($app->make(GitHubAppStore::class), $app->make(CachedGitHubApi::class)),
        );
        $this->app->singleton(
            CacheAgentStateView::class,
            static fn ($app): CacheAgentStateView => new CacheAgentStateView(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->bind(
            AgentViewSubscriber::class,
            static fn ($app): AgentViewSubscriber => new AgentViewSubscriber(
                socket: new StreamWebSocketClient,
                credentials: $app->make(WebSocketCredentialManager::class),
                view: $app->make(CacheAgentStateView::class),
                signer: $app->make(PresenceChannelSigner::class),
                log: $app->make(LoggerInterface::class),
                caPath: rtrim(string: Config::string('orbit.home'), characters: '/').'/ca/root.pem',
                commit: static function () use ($app): ?string {
                    $result = $app->make(ProcessRunner::class)->run(
                        // The stable path follows a release switch; base_path() stays on the release this process runs.
                        new ProcessInvocation(['git', '-C', GatewayApplicationPath::resolve(), 'rev-parse', 'HEAD'], timeout: 10.0),
                    );

                    return $result->succeeded() ? trim($result->stdout) : null;
                },
                clock: CacheAgentStateView::now(...),
                sleep: static function (float $seconds): void {
                    usleep((int) ($seconds * 1_000_000));
                },
                publisher: new ProcessAgentViewPublisher(
                    command: [PHP_BINARY, base_path('artisan'), 'orbit:agent-view-publish'],
                    log: $app->make(LoggerInterface::class),
                    clock: CacheAgentStateView::now(...),
                    workingDirectory: base_path(),
                ),
            ),
        );
        $this->app->bind(
            InstanceRuntimeReadiness::class,
            static fn ($app): RemoteInstanceRuntimeReadiness => new RemoteInstanceRuntimeReadiness(
                runtime: $app->make(ProcessRuntimeManager::class),
                timeoutSeconds: Config::integer('orbit.hibernation.wake_timeout_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->scoped(
            MetricsCredentialOperationLock::class,
            static fn (): MetricsCredentialOperationLock => new NativeMetricsCredentialOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/')
                    .'/locks/metrics-credentials',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            NodeCaddyBuildLock::class,
            static fn (): NodeCaddyBuildLock => new NodeCaddyBuildLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/caddy-build',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            ClusterRouterOperationLock::class,
            static fn (): ClusterRouterOperationLock => new NativeClusterRouterOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/cluster-router',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            InstanceEnvironmentOperationLock::class,
            static fn (): InstanceEnvironmentOperationLock => new NativeInstanceEnvironmentOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/')
                    .'/locks/app-instance-environment',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            ProcessAdmissionLock::class,
            static fn (): ProcessAdmissionLock => new NativeProcessAdmissionLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/process-admission',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(ProcessRuntimeLease::class, NativeProcessRuntimeLease::class);
        $this->app->scoped(
            DevelopmentProjectionOperationLock::class,
            static fn (): DevelopmentProjectionOperationLock => new NativeDevelopmentProjectionOperationLock(
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(NodeRoleFollowUpReport::class);
        $this->app->singleton(
            DnsmasqPrivateDnsManager::class,
            static fn (): DnsmasqPrivateDnsManager => new DnsmasqPrivateDnsManager(
                processes: app(ProcessRunner::class),
                renderer: app(DevelopmentDnsConfigRenderer::class),
                activateListener: true,
                vpnSettings: app(VpnSettings::class),
                ssh: app(SshExecutor::class),
                keys: app(SshKeyProvider::class),
                knownHosts: app(KnownHostsStore::class),
            ),
        );
        $this->app->singleton(CommandDeadline::class);
        $this->app->singleton(ToolCommandBudget::class);
        $this->app->scoped(MetricsReconcileDeferral::class);
        $this->app->singleton(
            ToolManagerRegistry::class,
            static fn (): ToolManagerRegistry => new ToolManagerRegistry([
                app(AptToolManager::class),
                app(VpToolManager::class),
                app(ComposerToolManager::class),
                app(HomebrewToolManager::class),
                app(HomebrewCaskToolManager::class),
            ]),
        );
        $this->app->singleton(
            AppDevSourceOperationLock::class,
            static fn (): AppDevSourceOperationLock => new NativeAppDevSourceOperationLock(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/app-dev-source',
            ),
        );
        $this->app->singleton(
            GatewaySelfAccessConverger::class,
            static fn (): GatewaySelfAccessConverger => new NativeGatewaySelfAccessConverger(
                processes: app(ProcessRunner::class),
                knownHosts: app(KnownHostsStore::class),
                sshKeys: app(SshKeyProvider::class),
                homeDirectory: self::resolveManagedUserHomeDirectory(...),
            ),
        );
        $this->app->singleton(
            KnownHostsRepository::class,
            static fn (): KnownHostsRepository => new KnownHostsRepository(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/ssh/known_hosts',
            ),
        );
        $this->app->alias(KnownHostsRepository::class, KnownHostsStore::class);
        $this->app->singleton(
            GatewaySshKeys::class,
            static fn (): GatewaySshKeys => new GatewaySshKeys(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/ssh/id_ed25519',
            ),
        );
        $this->app->alias(GatewaySshKeys::class, SshKeyProvider::class);
        $this->app->singleton(
            VpnConfigurationRepository::class,
            static fn (): VpnConfigurationRepository => new VpnConfigurationRepository(
                app(VpnSettings::class),
                rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
    }

    public function boot(): void
    {
        $this->commands([
            AgentViewCommand::class,
            AgentViewPublishCommand::class,
            AutoDeployCommand::class,
            BootstrapGatewayCommand::class,
            CaddyBuildCommand::class,
            CheckNodeDnsCommand::class,
            ConvergeGatewayWebCommand::class,
            ConvergeNodeRecipeCommand::class,
            FinalizeInterruptedActivitiesCommand::class,
            MachineRecipeCommand::class,
            MigrateDockerCommand::class,
            ProvisionNodeCommand::class,
            ReconcilePeerDnsCommand::class,
            RenderNodeProgramsCommand::class,
            RepairNodeDnsCommand::class,
            RetargetNodeCommand::class,
            RuntimeHibernatorCommand::class,
            ServePrivateDnsCommand::class,
        ]);
        $cache = config('cache');
        if (! is_array($cache)) {
            throw new \UnexpectedValueException('The Gateway cache configuration is invalid.');
        }
        $cache = ValidatedData::object($cache);
        if (! $this->app->runningConsoleCommand(GatewayCacheStore::RecoveryCommands)) {
            GatewayCacheStore::assertSupported($cache, $this->app->environment(), $this->app->configurationIsCached());
        }
        // Reverb event bodies carry text as UTF-8, so non-ASCII log lines keep their length (ADR 0153).
        $this->app->make(BroadcastManager::class)->extend(
            'reverb',
            fn ($app, array $config): ReverbBroadcaster => new ReverbBroadcaster($this->pusher($config), (bool) ($config['jsonp'] ?? false)),
        );
        Relation::morphMap([
            'instance' => Instance::class,
        ]);
    }

    private static function resolveManagedUserHomeDirectory(string $user): string|false
    {
        $account = posix_getpwnam($user);

        return is_array($account) ? (string) $account['dir'] : false;
    }
}
