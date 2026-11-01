<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;

it('resolves every Metrics production boundary', function (): void {
    expect([
        app(MetricsCredentialManager::class),
        app(MetricsCredentialRuntime::class),
        app(MetricsRuntimeLifecycle::class),
        app(MetricsExporterLifecycle::class),
        app(MetricsExporterProjection::class),
        app(MetricsCadvisorLifecycle::class),
        app(MetricsStatusReader::class),
    ])->each->toBeObject();
});
