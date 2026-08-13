<?php

declare(strict_types=1);

use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Doctor\DoctorIssueNodeResolver;
use App\Services\Doctor\DoctorManagedProxyRestorer;
use App\Services\Doctor\DoctorProxyRestorer;
use App\Services\Doctor\DoctorProxyRouteInventory;
use App\Services\Doctor\DoctorProxyRouteRestorer;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;

it('keeps proxy restore coordination behind one service', function (): void {
    $restorer = new ReflectionClass(DoctorProxyRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($dependencies)
        ->toBe([
            DoctorManagedProxyRestorer::class,
            DoctorProxyRouteRestorer::class,
            DoctorProxyRouteInventory::class,
            DoctorIssueNodeResolver::class,
        ])
        ->not->toContain(DoctorReportRunner::class);
});

it('separates managed proxy settings from saved proxy routes', function (): void {
    $managedRestorer = new ReflectionClass(DoctorManagedProxyRestorer::class);
    $managedDependencies = collect($managedRestorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $routeRestorer = new ReflectionClass(DoctorProxyRouteRestorer::class);
    $routeDependencies = collect($routeRestorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($managedDependencies)
        ->toBe([
            ProxyRouteFixer::class,
            WebSocketProxyDoctorProbe::class,
            S3ProxyDoctorProbe::class,
            AnalyticsProxyDoctorProbe::class,
            AnalyticsPublicProxyDoctorProbe::class,
        ])
        ->and($routeDependencies)
        ->toBe([
            ProxyRouteProbe::class,
            ProxyRouteFixer::class,
            ProxyRouteAdopter::class,
        ]);
});

it('keeps DoctorReportRunner as the proxy restore delegate', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $dependencies = collect($runner->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $runnerFile = $runner->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($dependencies)
        ->toContain(DoctorProxyRestorer::class)
        ->not->toContain(
            ProxyRouteProbe::class,
            ProxyRouteFixer::class,
            ProxyRouteAdopter::class,
            WebSocketProxyDoctorProbe::class,
            S3ProxyDoctorProbe::class,
            AnalyticsProxyDoctorProbe::class,
            AnalyticsPublicProxyDoctorProbe::class,
        )->and($runnerSource)->toContain(
            '$this->proxyRestorer->apply($node, $mode, $key, $detail, $issue)',
            '$this->proxyRestorer->issueTargetsWorkspace($detail)',
        )
        ->not->toContain(
            'private function applyProxyIssue(',
            'private function handleProxyCaddyContainerAction(',
            'private function handleAgentToolProxyAction(',
            'private function handleProxyGlobalConfigAction(',
            'private function handleWebSocketProxyAction(',
            'private function handleS3ProxyAction(',
            'private function handleAnalyticsProxyAction(',
            'private function handleAnalyticsPublicProxyAction(',
            'private function handleProxyAction(',
            'private function handleProxyExtraAction(',
        );

    expect(substr_count($runnerSource, needle: '$this->proxyRestorer->orderForRecovery($issues)'))->toBe(2);
});
