<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\AppDev\NativeAppDevTldConverger;
use App\Infrastructure\AppDev\NativeClusterRouterDnsSelectionReconciler;
use App\Infrastructure\AppDev\NativeDevelopmentProjectionOperationLock;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\AppDev\RemoteVitePortRuntime;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class AppDevServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AppDevCaddyManager::class => RemoteAppDevCaddyManager::class,
        AppDevPhpFpmManager::class => RemoteAppDevPhpFpmManager::class,
        AppDevTldConverger::class => NativeAppDevTldConverger::class,
        AppDevTldRouteManager::class => RemoteAppDevTldRouteManager::class,
        VitePortRuntime::class => RemoteVitePortRuntime::class,
        ClusterRouterDnsSelectionReconciler::class => NativeClusterRouterDnsSelectionReconciler::class,
    ];

    public function register(): void
    {
        $this->app->scoped(
            DevelopmentProjectionOperationLock::class,
            static fn (): DevelopmentProjectionOperationLock => new NativeDevelopmentProjectionOperationLock(
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                deadline: app(CommandDeadline::class),
            ),
        );

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
        $this->app->singleton(PrivateDnsManager::class, static fn (): PrivateDnsManager => app(DnsmasqPrivateDnsManager::class));
        $this->app->singleton(
            AppDevSourceOperationLock::class,
            static fn (): AppDevSourceOperationLock => new NativeAppDevSourceOperationLock(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/app-dev-source',
            ),
        );
    }
}
