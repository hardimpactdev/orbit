<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsFleetRuntimeMaterial;
use App\Domain\Metrics\MetricsPasswordSecrets;
use App\Domain\Metrics\MetricsPublicationManager as MetricsPublicationManagerContract;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsRuntimeSecrets;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Operations\OperationSecretSource;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Metrics\AgentMetricsCadvisorRuntime;
use App\Infrastructure\Metrics\AgentMetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsCadvisorRuntime;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsPublicationManager;
use App\Infrastructure\Metrics\MetricsRuntimeCommands;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Metrics\NativeMetricsCadvisorLifecycle;
use App\Infrastructure\Metrics\NativeMetricsContainerRuntime;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use App\Infrastructure\Metrics\NativeServiceMetricsLifecycle;
use App\Infrastructure\Metrics\NativeServiceMetricsRuntime;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
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
        MetricsExporterLifecycle::class => NativeMetricsExporterLifecycle::class,
        MetricsExporterProjection::class => NativeMetricsExporterProjection::class,
        MetricsFirewallExpectationProvider::class => NativeMetricsFirewallExpectationProvider::class,
        MetricsExporterRuntime::class => AgentMetricsExporterRuntime::class,
        ServiceMetricsLifecycle::class => NativeServiceMetricsLifecycle::class,
        ServiceMetricsRuntime::class => NativeServiceMetricsRuntime::class,
        ServiceMetricsProjection::class => ServiceMetricsProjection::class,
        MetricsCadvisorLifecycle::class => NativeMetricsCadvisorLifecycle::class,
        MetricsCadvisorRuntime::class => AgentMetricsCadvisorRuntime::class,
        MetricsFleetReconciler::class => NativeMetricsFleetReconciler::class,
        MetricsPublicationManagerContract::class => MetricsPublicationManager::class,
        MetricsRoleManager::class => NativeMetricsRoleManager::class,
        MetricsRuntimeLifecycle::class => NativeMetricsContainerRuntime::class,
        MetricsStatusReader::class => NativeMetricsStatusReader::class,
    ];

    public function register(): void
    {
        $this->app->tag([MetricsFleetRuntimeMaterial::class], OperationSecretSource::class);
        $this->app->tag([MetricsRuntimeSecrets::class, MetricsPasswordSecrets::class], OperationSecretSource::class);
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
