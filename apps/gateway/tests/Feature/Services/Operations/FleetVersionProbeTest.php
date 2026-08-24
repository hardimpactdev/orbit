<?php

declare(strict_types=1);

use App\Data\Nodes\InstalledAgentArtifact;
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
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()
        ->agent()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(sha256: str_repeat('c', times: 64)),
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
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
            'operator-1' => null,
        ])
        ->and($report->outdatedCount)
        ->toBe(2)
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
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
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
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(1)->and($report->allCurrent())->toBeFalse();
})->with([
    'missing image state' => [null],
    'different digest' => [fleetVersionProbeInstalledGatewayImage(digest: 'sha256:'.str_repeat('c', times: 64))],
]);

it('reports all current for an Ubuntu gateway when Agent artifacts exist', function (): void {
    $run = fleetVersionProbeRun();
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'ubuntu_24-04',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($gateway->fresh()->isFleetUpdateEligible())
        ->toBeFalse()
        ->and($report->outdatedCount)
        ->toBe(0)
        ->and($report->allCurrent())
        ->toBeTrue();
});

it('reports all current when the gateway digest and workload hashes match', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
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
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(0)->and($report->allCurrent())->toBeTrue();
});

it('updates app nodes for a new topology candidate build when CLI hashes are unchanged', function (): void {
    $run = fleetVersionProbeRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(
                identity: ['source' => 'topology-candidate', 'build_id' => 'previous-build'],
            ),
        ]);
    $candidateImage =
        'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-next-build@sha256:'
        .str_repeat('e', times: 64);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            options: [
                'manifest_source' => 'topology-candidate',
                'build_id' => 'next-build',
                'role_images' => [
                    'orbit-caddy' => 'caddy:2-alpine',
                    'orbit-frankenphp' => $candidateImage,
                ],
            ],
        ),
    );

    $probe = app(FleetVersionProbe::class);

    expect($probe->nodeNeedsUpdate($node, $plan))->toBeTrue();

    $node->forceFill([
        'installed_cli' => fleetVersionProbeInstalledCliArtifact(
            identity: ['source' => 'topology-candidate', 'build_id' => 'next-build'],
        ),
    ])->save();

    expect($probe->nodeNeedsUpdate($node->fresh(), $plan))->toBeFalse();
});

it('counts the gateway as outdated when matching artifacts came from a failed update run', function (): void {
    $run = fleetVersionProbeRun();
    $failedRun = fleetVersionProbeRun();
    app(OperationRunRecorder::class)->failed($failedRun->id, exitCode: 1, error: ['code' => 'verification_failed']);

    Node::factory()
        ->gateway()
        ->managed()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(operationRunId: $failedRun->id),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(operationRunId: $failedRun->id),
            'installed_agent' => fleetVersionProbeInstalledAgentArtifact(
                sha256: str_repeat('c', times: 64),
                operationRunId: $failedRun->id,
            ),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(1)->and($report->allCurrent())->toBeFalse();
});

it('counts a workload as outdated when matching artifacts came from a failed update run', function (): void {
    $run = fleetVersionProbeRun();
    $failedRun = fleetVersionProbeRun();
    app(OperationRunRecorder::class)->failed($failedRun->id, exitCode: 1, error: ['code' => 'verification_failed']);

    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()
        ->agent()
        ->managed()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(operationRunId: $failedRun->id),
            'installed_agent' => fleetVersionProbeInstalledAgentArtifact(
                sha256: str_repeat('c', times: 64),
                operationRunId: $failedRun->id,
            ),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(1)->and($report->allCurrent())->toBeFalse();
});

it('counts a roleless unmanaged node as outdated when CLI matches and Agent is absent', function (array $case): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => $case['name'],
            'managed' => false,
            'platform' => $case['platform'],
            'wireguard_address' => '10.6.0.80',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(
                platform: $case['artifact_platform'],
                sha256: $case['sha256'],
            ),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            cliArtifacts: $case['cli_artifacts'],
            agentArtifacts: $case['agent_artifacts'],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($node->fresh()->isAgentEligible())
        ->toBeFalse()
        ->and($report->outdatedCount)
        ->toBe(1)
        ->and($report->allCurrent())
        ->toBeFalse();
})->with([
    'linux' => [[
        'name' => 'operator-linux',
        'platform' => 'ubuntu_24-04',
        'artifact_platform' => 'linux-amd64',
        'sha256' => str_repeat('b', times: 64),
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
                'sha256' => str_repeat('b', times: 64),
            ],
        ],
        'agent_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
                'sha256' => str_repeat('c', times: 64),
            ],
        ],
    ]],
    'macos' => [[
        'name' => 'operator-mac',
        'platform' => 'macos_15-5',
        'artifact_platform' => 'darwin-arm64',
        'sha256' => str_repeat('f', times: 64),
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
                'sha256' => str_repeat('b', times: 64),
            ],
            'darwin-arm64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-macos-arm64',
                'sha256' => str_repeat('f', times: 64),
            ],
        ],
        'agent_artifacts' => [
            'darwin-arm64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-macos-arm64',
                'sha256' => str_repeat('c', times: 64),
            ],
        ],
    ]],
]);

it('compares macos workload nodes against darwin arm64 CLI artifacts', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'nmbp',
            'platform' => 'macos_26-5-1',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(
                platform: 'darwin-arm64',
                sha256: str_repeat('f', times: 64),
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
                    'sha256' => str_repeat('b', times: 64),
                ],
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/build/orbit-macos-arm64',
                    'sha256' => str_repeat('f', times: 64),
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

it('counts an agent-capable workload as outdated when its agent artifact differs', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()
        ->agent()
        ->managed()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
            'installed_agent' => fleetVersionProbeInstalledAgentArtifact(sha256: str_repeat('9', times: 64)),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(1)->and($report->allCurrent())->toBeFalse();
});

it('treats matching installed agent artifacts as current', function (): void {
    $run = fleetVersionProbeRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'installed_gateway_image' => fleetVersionProbeInstalledGatewayImage(),
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
        ]);
    Node::factory()
        ->agent()
        ->managed()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => fleetVersionProbeInstalledCliArtifact(),
            'installed_agent' => fleetVersionProbeInstalledAgentArtifact(sha256: str_repeat('c', times: 64)),
        ]);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVersionProbeSnapshot(
            '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(0)->and($report->allCurrent())->toBeTrue();
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
 * @param  array<string, array{url: string, sha256: string}>  $agentArtifacts
 * @param  array{manifest_source?: string, build_id?: string, role_images?: array<string, string>}  $options
 */
function fleetVersionProbeSnapshot(
    string $targetVersion,
    ?array $cliArtifacts = null,
    array $agentArtifacts = [],
    array $options = [],
): OperationUpdatePlanSnapshot {
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:'.$targetVersion.'@sha256:'.str_repeat('a', times: 64);

    $cliArtifacts ??= [
        'linux-amd64' => [
            'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v'.$targetVersion.'/orbit-linux-amd64',
            'sha256' => str_repeat('b', times: 64),
        ],
    ];
    $manifestSource = $options['manifest_source'] ?? 'github-release';
    $buildId = $options['build_id'] ?? null;
    $roleImages = $options['role_images'] ?? [
        'orbit-caddy' => 'caddy:2-alpine',
        'orbit-websocket' => 'hardimpact/orbit-reverb:'.$targetVersion.'@sha256:'.str_repeat('d', times: 64),
    ];

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: $manifestSource,
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => $manifestSource,
            ...($buildId !== null ? ['build_id' => $buildId] : []),
            'images' => ['gateway' => $gatewayImage],
            'cli_artifacts' => $cliArtifacts,
            'agent_artifacts' => $agentArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
        agentArtifacts: $agentArtifacts,
        roleImages: $roleImages,
    );
}

/**
 * @param  array{source?: string, build_id?: string}  $identity
 */
function fleetVersionProbeInstalledCliArtifact(
    string $sha256 = '',
    string $platform = 'linux-amd64',
    string $artifactUrl = 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
    ?string $operationRunId = null,
    array $identity = [],
): InstalledCliArtifact {
    return InstalledCliArtifact::record(
        version: '2.0.0',
        platform: $platform,
        sha256: $sha256 !== '' ? $sha256 : str_repeat('b', times: 64),
        source: $identity['source'] ?? 'github-release',
        buildId: $identity['build_id'] ?? null,
        artifactUrl: $artifactUrl,
        installedPath: '/home/orbit/orbit/bin/orbit-binary',
        operationRunId: $operationRunId ?? (string) Str::uuid(),
    );
}

function fleetVersionProbeInstalledAgentArtifact(
    string $sha256,
    string $platform = 'linux-amd64',
    string $artifactUrl = 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-agent-linux-x64',
    ?string $operationRunId = null,
): InstalledAgentArtifact {
    return InstalledAgentArtifact::record([
        'version' => '2.0.0',
        'platform' => $platform,
        'sha256' => $sha256,
        'source' => 'github-release',
        'build_id' => null,
        'artifact_url' => $artifactUrl,
        'installed_path' => '/usr/local/bin/orbit-agent',
        'operation_run_id' => $operationRunId ?? (string) Str::uuid(),
    ]);
}

function fleetVersionProbeInstalledGatewayImage(
    ?string $digest = null,
    ?string $operationRunId = null,
): InstalledGatewayImage {
    $digest ??= 'sha256:'.str_repeat('a', times: 64);

    return InstalledGatewayImage::record(
        version: '2.0.0',
        image: "ghcr.io/hardimpactdev/orbit-gateway:2.0.0@{$digest}",
        digest: $digest,
        source: 'github-release',
        buildId: null,
        operationRunId: $operationRunId ?? (string) Str::uuid(),
    );
}
