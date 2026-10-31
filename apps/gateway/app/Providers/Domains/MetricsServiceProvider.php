<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsCredentialResetMaterial;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Metrics\MetricsFleetRuntimeMaterial;
use App\Domain\Metrics\MetricsHostObserver;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Operations\OperationSecretSource;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Metrics\AgentMetricsHostObserver;
use App\Infrastructure\Metrics\MetricsRuntimeCommands;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class MetricsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        MetricsCredentialManager::class => NativeMetricsCredentialManager::class,
        MetricsAccessRevoker::class => NativeMetricsAccessRevoker::class,
        MetricsCredentialRuntime::class => MetricsRuntimeCommands::class,
        MetricsExporterProjection::class => NativeMetricsExporterProjection::class,
        MetricsFirewallExpectationProvider::class => NativeMetricsFirewallExpectationProvider::class,
        ServiceMetricsProjection::class => ServiceMetricsProjection::class,
        MetricsRoleManager::class => NativeMetricsRoleManager::class,
        MetricsStatusReader::class => NativeMetricsStatusReader::class,
        MetricsHostObserver::class => AgentMetricsHostObserver::class,
    ];

    public function register(): void
    {
        $this->app->tag([MetricsFleetRuntimeMaterial::class, MetricsCredentialResetMaterial::class], OperationSecretSource::class);
        $this->app->tag([ServiceMetricsCaddySiteSource::class], NodeCaddySiteSource::class);
        $this->app->tag([MetricsCaddySiteSource::class], NodeCaddySiteSource::class);
        $this->app->scoped(
            MetricsCredentialOperationLock::class,
            static fn (): MetricsCredentialOperationLock => new NativeMetricsCredentialOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/')
                    .'/locks/metrics-credentials',
                deadline: app(CommandDeadline::class),
            ),
        );
        // Shared for one request so the Metrics baseline's removal outcome
        // reaches the disable response instead of being inferred a second time.
        $this->app->scoped(MetricsPublicationReport::class);
        $this->app->scoped(MetricsReconcileDeferral::class);
    }
}
