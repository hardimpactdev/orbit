<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;

it('resolves every Metrics production boundary', function (): void {
    expect([
        app(MetricsCredentialManager::class),
        app(MetricsRuntimeLifecycle::class),
        app(MetricsExporterLifecycle::class),
        app(MetricsExporterProjection::class),
        app(MetricsStatusReader::class),
    ])->each->toBeObject();
});
