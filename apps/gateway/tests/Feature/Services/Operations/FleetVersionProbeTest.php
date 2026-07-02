<?php

declare(strict_types=1);

use App\Data\Nodes\InstalledCliArtifact;
use App\Data\Nodes\InstalledGatewayImage;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\FleetVersionProbe;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('compares gateway image and workload CLI artifact identity from node DTOs', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
        ]);
    Node::factory()
        ->agent()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(sha256: str_repeat('c', 64)),
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()->operator()->create(['name' => 'operator-1']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->targetVersion)
        ->toBe('2.0.0')
        ->and($report->gatewayVersion)
        ->toBe('2.0.0')
        ->and($report->nodeVersions)
        ->toBe([
            'agent-1' => '2.0.0',
            'app-dev-1' => '2.0.0',
        ])
        ->and($report->outdatedCount)
        ->toBe(1)
        ->and($report->allCurrent())
        ->toBeFalse();
});

it('treats missing workload CLI state as outdated', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
        ]);
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->nodeVersions)->toBe(['agent-1' => null])->and($report->outdatedCount)->toBe(1);
});

it('counts the gateway as outdated when its tracked image is missing or differs', function (?InstalledGatewayImage $installedGatewayImage): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => $installedGatewayImage,
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(1)->and($report->allCurrent())->toBeFalse();
})->with([
    'missing image state' => [null],
    'different digest' => [fleetVersionProbeInstalledGatewayImage(digest: 'sha256:'.str_repeat('c', 64))],
]);

it('reports all current when the gateway digest and workload hashes match', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
        ]);
    Node::factory()
        ->agent()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(0)->and($report->allCurrent())->toBeTrue();
});

it('compares macos workload nodes against darwin arm64 CLI artifacts', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'nmbp',
            'platform' => 'macos_26-5-1',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(
                platform: 'darwin-arm64',
                sha256: str_repeat('f', 64),
                artifactUrl: 'https://artifacts.orbit/releases/candidates/build/orbit-macos-arm64',
            ),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            cliArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/build/orbit-linux-x64',
                    'sha256' => str_repeat('b', 64),
                ],
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/build/orbit-macos-arm64',
                    'sha256' => str_repeat('f', 64),
                ],
            ],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->nodeVersions)
        ->toBe(['nmbp' => '2.0.0'])
        ->and($report->outdatedCount)
        ->toBe(0)
        ->and($report->allCurrent())
        ->toBeTrue();
});

it('falls back to the baked app version when no gateway node exists', function (): void {
    config()->set('app.version', '2.0.0');

    $run = fleetVersionProbeRun();
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->gatewayVersion)
        ->toBe('2.0.0')
        ->and($report->outdatedCount)
        ->toBe(0)
        ->and($report->allCurrent())
        ->toBeTrue();
});

function fleetVersionProbeRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @param  array<string, array{url: string, sha256: string}>|null  $cliArtifacts
 */
function fleetVersionProbeSnapshot(string $targetVersion, ?array $cliArtifacts = null): OperationUpdatePlanSnapshot
{
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:'.$targetVersion.'@sha256:'.str_repeat('a', 64);

    $cliArtifacts ??= [
        'linux-amd64' => [
            'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v'.$targetVersion.'/orbit-linux-amd64',
            'sha256' => str_repeat('b', 64),
        ],
    ];
    $roleImages = [
        'orbit-caddy' => 'caddy:2-alpine',
        'orbit-websocket' => 'hardimpact/orbit-reverb:'.$targetVersion.'@sha256:'.str_repeat('d', 64),
    ];

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => 'github-release',
            'images' => ['gateway' => $gatewayImage],
            'cli_artifacts' => $cliArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
        roleImages: $roleImages,
    );
}

function fleetVersionProbeInstalledCliArtifact(
    string $sha256 = '',
    string $platform = 'linux-amd64',
    string $artifactUrl = 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
): InstalledCliArtifact {
    return InstalledCliArtifact::record(
        version: '2.0.0',
        platform: $platform,
        sha256: $sha256 !== '' ? $sha256 : str_repeat('b', 64),
        source: 'github-release',
        buildId: null,
        artifactUrl: $artifactUrl,
        installedPath: '/home/orbit/orbit/bin/orbit-binary',
        operationRunId: (string) Str::uuid(),
    );
}

function fleetVersionProbeInstalledGatewayImage(?string $digest = null): InstalledGatewayImage
{
    $digest ??= 'sha256:'.str_repeat('a', 64);

    return InstalledGatewayImage::record(
        version: '2.0.0',
        image: "ghcr.io/hardimpactdev/orbit-gateway:2.0.0@{$digest}",
        digest: $digest,
        source: 'github-release',
        buildId: null,
        operationRunId: (string) Str::uuid(),
    );
}
