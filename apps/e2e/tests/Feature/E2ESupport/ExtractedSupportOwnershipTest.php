<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyNetworkPlan;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2EResourceLease;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2EResourceLeaseSet;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyUnavailable;
use App\E2E\Support\ProviderAvailability;
use App\E2E\Support\SshKeyPair;

it('owns extracted standalone support primitives outside the gateway app', function (): void {
    $classes = [
        DockerTopologyNetworkPlan::class,
        E2EConfig::class,
        E2EImage::class,
        E2EPhaseTimer::class,
        E2EPreparedTopology::class,
        E2EResourceLease::class,
        E2EResourceLeasePool::class,
        E2EResourceLeaseSet::class,
        E2ETopologyAcquisitionOptions::class,
        E2ETopologyArtifactNamespace::class,
        E2ETopologyCapabilities::class,
        E2ETopologyKind::class,
        E2ETopologyUnavailable::class,
        ProviderAvailability::class,
        SshKeyPair::class,
    ];

    foreach ($classes as $class) {
        expect(class_exists($class) || enum_exists($class))->toBeTrue($class);

        $reflection = new ReflectionClass($class);
        $fileName = $reflection->getShortName().'.php';

        expect(realpath((string) $reflection->getFileName()))
            ->toBe(realpath(repo_path("apps/e2e/app/E2E/Support/{$fileName}")))
            ->and(repo_path("apps/gateway/app/E2E/Support/{$fileName}"))
            ->not->toBeFile();
    }
});

it('keeps the gateway runner pointed at extracted support during the migration', function (): void {
    $composer = json_decode(
        (string) file_get_contents(repo_path('apps/gateway/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['autoload']['psr-4']['App\\E2E\\Support\\'] ?? null)
        ->toBe('../e2e/app/E2E/Support/');
});

it('classifies the former gateway support layer for the next extraction batches', function (): void {
    $extractedStandalone = [
        'DockerTopologyNetworkPlan',
        'E2EConfig',
        'E2EImage',
        'E2EPhaseTimer',
        'E2EPreparedTopology',
        'E2EResourceLease',
        'E2EResourceLeasePool',
        'E2EResourceLeaseSet',
        'E2ETopologyAcquisitionOptions',
        'E2ETopologyArtifactNamespace',
        'E2ETopologyCapabilities',
        'E2ETopologyKind',
        'E2ETopologyUnavailable',
        'ProviderAvailability',
        'SshKeyPair',
    ];

    $gatewayIntegratedUntilRewritten = [
        'DockerHost',
        'DockerInstance',
        'DockerTopologyBuilder',
        'DockerTopologyProvider',
        'E2ECommand',
        'E2ECurrentCheckout',
        'E2EGatewayApi',
        'E2EInstance',
        'E2ENetwork',
        'E2ENodeProbe',
        'E2EOperatorIdentity',
        'E2EPreparedTopologyRegistry',
        'E2EProvider',
        'E2EReachability',
        'E2ERun',
        'E2ETopologyCache',
        'E2ETopologyFactory',
        'E2ETopologyHarness',
        'E2ETopologyLease',
        'E2ETopologyProvider',
        'E2ETopologyProviderPool',
        'E2ETopologyProviderSelection',
        'E2EWgEasyGateway',
        'E2EWireGuardMesh',
        'IncusHost',
        'IncusHostPool',
        'IncusInstance',
        'IncusProvider',
        'IncusTopologyBuilder',
        'IncusTopologyProvider',
        'IncusTopologyTemplate',
        'IncusWarmTopologyPool',
        'ProviderPool',
        'ProviderSelection',
    ];

    $allFormerGatewaySupportClasses = [
        ...$extractedStandalone,
        ...$gatewayIntegratedUntilRewritten,
    ];

    expect($allFormerGatewaySupportClasses)
        ->toHaveCount(49)
        ->and(array_unique($allFormerGatewaySupportClasses))
        ->toHaveCount(49);

    foreach ($extractedStandalone as $class) {
        expect(repo_path("apps/e2e/app/E2E/Support/{$class}.php"))->toBeFile()
            ->and(repo_path("apps/gateway/app/E2E/Support/{$class}.php"))->not->toBeFile();
    }

    foreach ($gatewayIntegratedUntilRewritten as $class) {
        expect(repo_path("apps/gateway/app/E2E/Support/{$class}.php"))->toBeFile()
            ->and(repo_path("apps/e2e/app/E2E/Support/{$class}.php"))->not->toBeFile();
    }
});
