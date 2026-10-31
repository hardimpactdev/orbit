<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Metrics\AdmitMetricsFleetAction;
use App\Domain\Metrics\MetricsHostObserver;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsStatusReader;

it('resolves retained Metrics state observation and native admission boundaries', function (): void {
    expect([
        app(MetricsCredentialManager::class),
        app(MetricsCredentialRuntime::class),
        app(MetricsExporterProjection::class),
        app(MetricsFirewallExpectationProvider::class),
        app(MetricsHostObserver::class),
        app(AdmitMetricsFleetAction::class),
        app(MetricsRoleManager::class),
        app(MetricsStatusReader::class),
    ])->each->toBeObject();
});
