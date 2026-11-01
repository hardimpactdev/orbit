<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Metrics\MetricsFleetRuntimeMaterial;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Operations\OperationSecretSource;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Metrics\AgentMetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsRuntimeCommands;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use Illuminate\Support\ServiceProvider;

final class MetricsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        MetricsCredentialManager::class => NativeMetricsCredentialManager::class,
        MetricsAccessRevoker::class => NativeMetricsAccessRevoker::class,
        MetricsExporterLifecycle::class => NativeMetricsExporterLifecycle::class,
        MetricsExporterProjection::class => NativeMetricsExporterProjection::class,
        MetricsFirewallExpectationProvider::class => NativeMetricsFirewallExpectationProvider::class,
        MetricsExporterRuntime::class => AgentMetricsExporterRuntime::class,
        ServiceMetricsProjection::class => ServiceMetricsProjection::class,
        MetricsRuntimeLifecycle::class => MetricsRuntimeCommands::class,
        MetricsStatusReader::class => NativeMetricsStatusReader::class,
    ];

    public function register(): void
    {
        $this->app->tag(MetricsFleetRuntimeMaterial::class, OperationSecretSource::class);
        $this->app->tag([ServiceMetricsCaddySiteSource::class], NodeCaddySiteSource::class);
        $this->app->tag([MetricsCaddySiteSource::class], NodeCaddySiteSource::class);
    }
}
