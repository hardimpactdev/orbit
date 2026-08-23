<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Nodes\InstalledAgentArtifact;
use App\Data\Nodes\InstalledCliArtifact;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\RemoteNodeDoctor;
use App\Services\Operations\UpdateLeaseManager;
use App\Services\Operations\UpdateRunner;
use App\Services\Operations\WorkloadNodeUpdateFailed;
use App\Services\Operations\WorkloadNodeUpdater;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RemoteShellMetadata;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(GatewayCliArtifactRelay::class, new WorkloadUpdaterFakeArtifactRelay);
    app()->instance(RemoteNodeDoctor::class, new WorkloadUpdaterFakeNodeDoctor);
    app()->instance(OrbitCaService::class, new WorkloadUpdaterFakeCa);
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-identity',
            'tld' => 'gateway',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.2',
        ]);
});

it('excludes the caller-local node from remote workload updates', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $caller = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'beast',
            'platform' => 'ubuntu_24-04',
        ]);
    $run = workloadUpdaterRun(callerNodeId: $caller->id);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            cliArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.test/orbit-linux-x64',
                    'sha256' => str_repeat('b', times: 64),
                ],
                'darwin-arm64' => [
                    'url' => 'https://artifacts.test/orbit-macos-arm64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect(array_column($results, 'node'))
        ->toBe(['beast'])
        ->and(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['beast']);
});

it('updates active non-gateway managed nodes from the persisted manifest snapshot', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $appDev = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'orbit_path' => '/opt/orbit-app-dev',
        ]);
    $appProd = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-prod-1',
            'platform' => 'ubuntu',
            'orbit_path' => '/opt/orbit-app-prod',
        ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $appProd->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->database()->create(['name' => 'database-1', 'platform' => 'ubuntu']);
    Node::factory()->ingress()->create(['name' => 'ingress-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->appDev()->create(['name' => 'gateway-app']);
    Node::factory()->operator()->create(['name' => 'operator-1']);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workload_updater_snapshot_with_role_image_artifact(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
                    'sha256' => str_repeat('e', times: 64),
                ],
            ],
            roleImages: [
                'orbit-caddy' => 'caddy:2.9-alpine',
                'orbit-frankenphp' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
                'orbit-websocket' => 'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'agent-1',
                'node' => 'agent-1',
                'roles' => ['agent'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
            [
                'target' => 'app-prod-1',
                'node' => 'app-prod-1',
                'roles' => ['app-prod', 'websocket'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
            [
                'target' => 'database-1',
                'node' => 'database-1',
                'roles' => ['database'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
            [
                'target' => 'ingress-1',
                'node' => 'ingress-1',
                'roles' => ['ingress'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['agent-1', 'app-dev-1', 'app-prod-1', 'database-1', 'ingress-1'])
        ->and($shell->calls[0]['options']['metadata'])
        ->toBe([
            'ORBIT_OPERATION_ID' => $run->id,
            'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
        ])
        ->and($shell->calls[0]['command_options'])
        ->toBe([])
        ->and($shell->calls[0]['options'])
        ->not
        ->toHaveKey('transport')
        ->and($shell->calls[0]['options']['cwd'] ?? null)
        ->toBe('/home/orbit')
        ->and($shell->calls[0]['options']['environment'] ?? null)
        ->toBe([
            'HOME' => '/home/orbit',
            'ORBIT_CONFIG_PATH' => '/home/orbit/.config/orbit/config.json',
            'ORBIT_INSTALL_METADATA_PATH' => '/home/orbit/.config/orbit/install.json',
        ])
        ->and($shell->calls[0]['options']['bind_application_key'] ?? null)
        ->toBeFalse()
        ->and($shell->calls[0]['options']['bind_input'] ?? null)
        ->toBeTrue()
        ->and($shell->updateScriptCallsFor('agent-1'))
        ->toBe(2)
        ->and($shell->updateScriptCallsFor('database-1'))
        ->toBe(2)
        ->and($shell->activeLeases)
        ->toBe([
            'agent-1' => ['node:agent-1'],
            'app-dev-1' => ['node:app-dev-1'],
            'app-prod-1' => ['node:app-prod-1'],
            'database-1' => ['node:database-1'],
            'ingress-1' => ['node:ingress-1'],
        ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);

    expect(workload_updater_full_install_payload($shell, node: 'agent-1'))
        ->toMatchArray([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/cli/linux-amd64?token=fake",
            'sha256' => str_repeat('e', times: 64),
            'install_root' => '/home/orbit/orbit',
            'bin_path' => '/home/orbit/.local/bin/orbit',
            'shared_binary_path' => null,
            'role_images' => ['caddy:2.9-alpine'],
        ])
        ->and(workload_updater_install_payload($shell, node: 'agent-1'))
        ->toMatchArray([
            'agent_artifact' => null,
            'agent_service' => null,
            'role_images' => [],
            'role_image_artifacts' => [],
            'role_image_aliases' => [],
        ])
        ->and(workload_updater_full_install_payload($shell, node: 'app-dev-1'))
        ->toMatchArray([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/cli/linux-amd64?token=fake",
            'sha256' => str_repeat('e', times: 64),
            'role_images' => [
                'caddy:2.9-alpine',
                'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            ],
        ])
        ->and(workload_updater_full_install_payload($shell, node: 'app-prod-1')['role_images'])
        ->toBe([
            'caddy:2.9-alpine',
            'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'hardimpact/orbit-reverb:2.0.0',
        ])
        ->and(workload_updater_full_install_payload($shell, node: 'app-prod-1')['role_image_artifacts'])
        ->toBe([
            [
                'image' => 'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
                'url' => 'https://artifacts.test/orbit-reverb-linux-amd64.tar',
                'sha256' => str_repeat('f', times: 64),
            ],
        ])
        ->and(workload_updater_install_payload($shell, node: 'database-1')['role_images'])
        ->toBe([])
        ->and(workload_updater_full_install_payload($shell, node: 'ingress-1')['role_images'])
        ->toBe(['caddy:2.9-alpine']);

    $installedCli = $appDev->fresh()->installed_cli;

    expect($installedCli)
        ->toBeInstanceOf(InstalledCliArtifact::class)
        ->and($installedCli?->version)
        ->toBe('2.0.0')
        ->and($installedCli?->sha256)
        ->toBe(str_repeat('e', times: 64))
        ->and($installedCli?->source)
        ->toBe('github-release')
        ->and($installedCli?->artifactUrl)
        ->toBe('https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64');
});

it('installs and records agent artifacts for Agent-eligible workload nodes', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.50',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])
        ->toBe('completed')
        ->and(workload_updater_full_install_payload($shell, node: 'app-dev-1')['agent_artifact'])
        ->toBe([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/agent/linux-amd64?token=fake",
            'sha256' => str_repeat('9', times: 64),
            'bin_path' => '/home/orbit/.local/bin/orbit-agent',
        ])
        ->and(workload_updater_full_install_payload($shell, node: 'app-dev-1')['agent_service'])
        ->toMatchArray([
            'ca_path' => '/home/orbit/.config/orbit/ca/root.crt',
            'ca_pem' => "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n",
        ])
        ->and($node->fresh()->installed_agent)
        ->toBeInstanceOf(InstalledAgentArtifact::class)
        ->and($node->fresh()->installed_agent?->sha256)
        ->toBe(str_repeat('9', times: 64))
        ->and($node->fresh()->installed_agent?->artifactUrl)
        ->toBe('https://artifacts.orbit/candidates/build/orbit-agent-linux-x64');
});

it('bootstraps the candidate CLI before full Agent and role-image install on Services1-like nodes', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'services1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.91',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
            ],
            roleImages: [
                'orbit-websocket' => 'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);
    $payloads = workload_updater_install_payloads($shell, node: 'services1');

    expect($results[0]['status'])
        ->toBe('completed')
        ->and($payloads)
        ->toHaveCount(2)
        ->and($payloads[0])
        ->toMatchArray([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/cli/linux-amd64?token=fake",
            'sha256' => str_repeat('b', times: 64),
            'install_root' => '/home/orbit/orbit',
            'bin_path' => '/home/orbit/.local/bin/orbit',
            'shared_binary_path' => null,
            'agent_artifact' => null,
            'agent_service' => null,
            'role_images' => [],
            'role_image_artifacts' => [],
            'role_image_aliases' => [],
        ])
        ->and($payloads[1]['agent_artifact'])
        ->toBe([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/agent/linux-amd64?token=fake",
            'sha256' => str_repeat('9', times: 64),
            'bin_path' => '/home/orbit/.local/bin/orbit-agent',
        ])
        ->and($payloads[1]['agent_service'])
        ->toBeArray()
        ->and($payloads[1]['role_images'])
        ->toBe([
            'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        ])
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(['workload.services1', 'running', 'Installing Orbit Agent artifact'])
        ->and($node->fresh()->installed_cli?->version)
        ->toBe('2.0.0')
        ->and($node->fresh()->installed_agent?->sha256)
        ->toBe(str_repeat('9', times: 64));
});

it('preserves a failed installer result before checking Agent artifact confirmation', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteShellResult(
            exitCode: 12,
            stdout: '',
            stderr: 'required image load failed',
            durationMs: 20,
        ),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0])
        ->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'remote_update',
            'output' => 'required image load failed',
        ])
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(['workload.app-dev-1', 'fail', 'required image load failed']);
});

it('installs macos agent artifacts into the user local agent binary path', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'installed_cli' => InstalledCliArtifact::record(
                version: '1.0.0',
                platform: 'darwin-arm64',
                sha256: str_repeat('a', times: 64),
                source: 'github-release',
                buildId: null,
                artifactUrl: 'https://github.com/hardimpactdev/orbit/releases/download/v1.0.0/orbit-macos-arm64',
                installedPath: '/Users/nckrtl/orbit/bin/orbit-binary',
                operationRunId: (string) Str::uuid(),
            ),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-macos-arm64',
                    'sha256' => str_repeat('8', times: 64),
                ],
            ],
            agentArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-macos-arm64',
                    'sha256' => str_repeat('7', times: 64),
                ],
            ],
        ),
    );

    Http::fake([
        "http://{$node->wireguard_address}:9477/*" => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])
        ->toBe('completed')
        ->and(workload_updater_full_install_payload($shell, node: 'mini')['agent_artifact'])
        ->toBe([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/agent/darwin-arm64?token=fake",
            'sha256' => str_repeat('7', times: 64),
            'bin_path' => '/Users/nckrtl/.local/bin/orbit-agent',
        ])
        ->and($node->fresh()->installed_agent)
        ->toBeInstanceOf(InstalledAgentArtifact::class)
        ->and($node->fresh()->installed_agent?->installedPath)
        ->toBe('/Users/nckrtl/.local/bin/orbit-agent')
        ->and($node->fresh()->installed_agent?->artifactUrl)
        ->toBe('https://artifacts.orbit/candidates/build/orbit-agent-macos-arm64');
});

it('retries Agent artifact installs through the canonical macos launcher', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-macos-arm64',
                    'sha256' => str_repeat('8', times: 64),
                ],
            ],
            agentArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-macos-arm64',
                    'sha256' => str_repeat('7', times: 64),
                ],
            ],
        ),
    );

    Http::fake([
        "http://{$node->wireguard_address}:9477/*" => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);
    $payloads = workload_updater_install_payloads($shell, node: 'mini');

    expect($results[0]['status'])
        ->toBe('completed')
        ->and($shell->updateScriptCallsFor('mini'))
        ->toBe(2)
        ->and($payloads[0]['agent_artifact'])
        ->toBeNull()
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(['workload.mini', 'running', 'Installing Orbit Agent artifact'])
        ->and(workload_updater_full_install_payload($shell, node: 'mini')['agent_artifact'])
        ->toBe([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/agent/darwin-arm64?token=fake",
            'sha256' => str_repeat('7', times: 64),
            'bin_path' => '/Users/nckrtl/.local/bin/orbit-agent',
        ])
        ->and($node->fresh()->installed_agent?->sha256)
        ->toBe(str_repeat('7', times: 64));
});

it('records agent artifact installs when the full-stage disconnects during agent restart', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'mini' => [
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'installed' => true,
                            'bin_path' => '/Users/nckrtl/.local/bin/orbit',
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 15,
            ),
            new RemoteLocalExecutorTransportFailed(
                'Remote local executor transport failed: cURL error 52: Empty reply from server',
            ),
        ],
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-macos-arm64',
                    'sha256' => str_repeat('8', times: 64),
                ],
            ],
            agentArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-macos-arm64',
                    'sha256' => str_repeat('7', times: 64),
                ],
            ],
        ),
    );

    Http::fake([
        "http://{$node->wireguard_address}:9477/*" => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])
        ->toBe('completed')
        ->and($shell->updateScriptCallsFor('mini'))
        ->toBe(2)
        ->and($node->fresh()->installed_agent?->sha256)
        ->toBe(str_repeat('7', times: 64));
});

it('excludes Agent-ineligible workload nodes from fleet updates', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toBeEmpty()
        ->and($shell->calls)
        ->toBeEmpty()
        ->and($node->fresh()->installed_agent)
        ->toBeNull()
        ->and($node->fresh()->installed_cli?->version)
        ->toBe('1.0.0');
});

it('skips a workload node already on the target version and runs no remote update', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '2.0.0'),
        ]);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'ubuntu_24-04']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'skipped',
            ],
            [
                'target' => 'app-prod-1',
                'node' => 'app-prod-1',
                'roles' => ['app-prod'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['app-prod-1'])
        ->and($shell->scriptsFor('app-dev-1'))
        ->toBe([])
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(
            ['workload.app-dev-1', 'done', 'Workload node app-dev-1 skipped: already up to date'],
        )
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);
});

it('skips a selected role-bearing macOS node whose Agent is unavailable before mutation', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $mac = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mini',
            'managed' => false,
            'platform' => 'macos_15-5',
            'architecture' => 'arm64',
            'wireguard_address' => '10.6.0.80',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.test/orbit-macos-arm64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    Http::fake([
        'http://10.6.0.80:9477/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'mini',
                'node' => 'mini',
                'roles' => ['app-dev'],
                'status' => 'skipped',
                'reason' => 'orbit_desktop_not_running',
            ],
        ])
        ->and($shell->calls)
        ->toBeEmpty()
        ->and($mac->fresh()->installed_cli)
        ->toBeNull()
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(
            ['workload.mini', 'done', 'Workload node mini skipped: Orbit Desktop is not running'],
        )
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);
});

it('skips a managed macOS client whose Agent is unavailable before mutation', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $mac = Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'managed' => true,
            'platform' => 'macos_15-5',
            'architecture' => 'arm64',
            'wireguard_address' => '10.6.0.80',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.test/orbit-macos-arm64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    Http::fake([
        'http://10.6.0.80:9477/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'mini',
                'node' => 'mini',
                'roles' => [],
                'status' => 'skipped',
                'reason' => 'orbit_desktop_not_running',
            ],
        ])
        ->and($shell->calls)
        ->toBeEmpty()
        ->and($mac->fresh()->installed_cli)
        ->toBeNull()
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(
            ['workload.mini', 'done', 'Workload node mini skipped: Orbit Desktop is not running'],
        )
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);
});

it('stages a desktop archive and pending automatic handoff for a reachable selected role-bearing Mac', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-app-dev',
            'managed' => false,
            'platform' => 'macos_15-5',
            'architecture' => 'arm64',
            'wireguard_address' => '10.6.0.81',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $desktop = workload_updater_desktop_artifact();
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workload_updater_darwin_snapshot(),
    );

    Http::fake([
        'http://10.6.0.81:9477/*' => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);
    $payload = workload_updater_full_install_payload($shell, node: 'mac-app-dev');

    expect($results[0]['status'] ?? null)
        ->toBe('completed')
        ->and($payload['desktop_artifact'])
        ->toMatchArray([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/desktop/darwin-arm64?token=fake",
            'sha256' => $desktop['sha256'],
            'signature' => $desktop['signature'],
            'version' => '1.2.3',
            'platform' => 'darwin',
            'architecture' => 'arm64',
            'staged_path' =>
                '/Users/nckrtl/.local/share/orbit/updates/desktop-'
                    .substr($desktop['sha256'], offset: 0, length: 12)
                    .'.tar.gz',
        ])
        ->and($payload['pending_desktop_update'])
        ->toMatchArray([
            'path' => '/Users/nckrtl/.config/orbit/pending-desktop-update.json',
            'operation_id' => $run->id,
            'version' => '1.2.3',
            'build_id' => null,
            'install_mode' => 'automatic',
        ]);
});

it('omits an incomplete desktop identity when the same-platform Agent artifact is absent', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->operator()
        ->managed()
        ->create([
            'name' => 'mini',
            'platform' => 'macos_15-5',
            'architecture' => 'arm64',
            'wireguard_address' => '10.6.0.82',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workload_updater_darwin_snapshot_without_agent(),
    );

    Http::fake([
        'http://10.6.0.82:9477/*' => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);
    $payload = workload_updater_full_install_payload($shell, node: 'mini');

    expect($results[0]['status'] ?? null)
        ->toBe('completed')
        ->and($payload['agent_artifact'])
        ->toBeNull()
        ->and($payload['desktop_artifact'])
        ->toBeNull()
        ->and($payload['pending_desktop_update'])
        ->toBeNull();
});

it('stages a desktop archive and pending automatic handoff for a reachable managed Mac', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->operator()
        ->managed()
        ->create([
            'name' => 'mini',
            'platform' => 'macos_15-5',
            'architecture' => 'arm64',
            'wireguard_address' => '10.6.0.80',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $desktop = workload_updater_desktop_artifact();
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workload_updater_darwin_snapshot(),
    );

    Http::fake([
        'http://10.6.0.80:9477/*' => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);
    $payload = workload_updater_full_install_payload($shell, node: 'mini');

    expect($results)
        ->toMatchArray([
            [
                'target' => 'mini',
                'node' => 'mini',
                'roles' => [],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and($payload['desktop_artifact'])
        ->toMatchArray([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/desktop/darwin-arm64?token=fake",
            'sha256' => $desktop['sha256'],
            'signature' => $desktop['signature'],
            'version' => '1.2.3',
            'platform' => 'darwin',
            'architecture' => 'arm64',
            'staged_path' =>
                '/Users/nckrtl/.local/share/orbit/updates/desktop-'
                    .substr($desktop['sha256'], offset: 0, length: 12)
                    .'.tar.gz',
        ])
        ->and($payload['pending_desktop_update'])
        ->toMatchArray([
            'path' => '/Users/nckrtl/.config/orbit/pending-desktop-update.json',
            'operation_id' => $run->id,
            'version' => '1.2.3',
            'build_id' => null,
            'install_mode' => 'automatic',
        ]);
});

it('fails a non-managed role-bearing target when the Agent is unavailable', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteLocalExecutorTransportFailed('Connection refused'),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.81',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => 'Connection refused',
            ],
        ])
        ->and($shell->updatedNodes())
        ->toBe(['app-dev-1']);
});

it('cannot relabel a managed macOS client as skipped after mutation starts', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'mini' => new RemoteShellResult(
            exitCode: 12,
            stdout: '',
            stderr: 'install failed after download',
            durationMs: 10,
        ),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'managed' => true,
            'platform' => 'macos_15-5',
            'architecture' => 'arm64',
            'wireguard_address' => '10.6.0.82',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://artifacts.test/orbit-macos-arm64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
        ),
    );

    Http::fake([
        'http://10.6.0.82:9477/*' => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'mini',
                'node' => 'mini',
                'roles' => [],
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => 'install failed after download',
            ],
        ])
        ->and($results[0]['status'] ?? null)
        ->not
        ->toBe('skipped')
        ->and($shell->updatedNodes())
        ->toBe(['mini']);
});

it('runs workload installs through the typed Agent-push local executor', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['app-dev-1'])
        ->and($shell->calls[0]['script'])
        ->toBe('internal:fleet-update:install-cli')
        ->and($shell->calls[0]['options'])
        ->not
        ->toHaveKey('transport')
        ->and($shell->calls[0]['options']['cwd'] ?? null)
        ->toBe('/home/orbit')
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(2)
        ->and($node->fresh()->installed_cli?->version)
        ->toBe('2.0.0');
});

it('retries workload CLI installs when the previous launcher exits during self update', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => [
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: '', durationMs: 3),
            new RemoteShellResult(exitCode: 0, stdout: "updated\n", stderr: '', durationMs: 20),
        ],
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);
    $payloads = workload_updater_install_payloads($shell, node: 'app-dev-1');

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(3)
        ->and($payloads[0]['role_images'])
        ->toBeEmpty()
        ->and($payloads[0]['agent_artifact'])
        ->toBeNull()
        ->and($shell->calls[0]['options']['input'])
        ->toBe($shell->calls[1]['options']['input'])
        ->and($payloads[2]['role_images'])
        ->not
        ->toBeEmpty()
        ->and($node->fresh()->installed_cli?->version)
        ->toBe('2.0.0');
});

it('records workload CLI installs when the Agent transport disconnects during self update', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteLocalExecutorTransportFailed(
            'Remote local executor transport failed: cURL error 52: Empty reply from server',
        ),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(2)
        ->and($node->fresh()->installed_cli?->version)
        ->toBe('2.0.0')
        ->and($node->fresh()->installed_agent?->sha256)
        ->toBe(str_repeat('9', times: 64));
});

it('keeps workload transport failures that are not agent restart disconnects failed', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteLocalExecutorTransportFailed(
            'Remote local executor transport failed: cURL error 7: Failed to connect',
        ),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '1.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => 'Remote local executor transport failed: cURL error 7: Failed to connect',
            ],
        ])
        ->and($node->fresh()->installed_cli?->version)
        ->toBe('1.0.0');
});

it('does not send role images to macos workload cli installers', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'darwin-arm64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-macos-arm64',
                    'sha256' => str_repeat('a', times: 64),
                ],
            ],
            roleImages: [
                'orbit-caddy' => 'caddy:2.9-alpine',
                'orbit-websocket' => 'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
            ],
        ),
    );

    Http::fake([
        "http://{$node->wireguard_address}:9477/*" => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'mini',
                'node' => 'mini',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(workload_updater_install_payload($shell, node: 'mini'))
        ->toMatchArray([
            'artifact_url' => "http://gateway.test/api/update/artifacts/{$run->id}/cli/darwin-arm64?token=fake",
            'sha256' => str_repeat('a', times: 64),
            'install_root' => '/Users/nckrtl/orbit',
            'bin_path' => '/Users/nckrtl/.local/bin/orbit',
            'shared_binary_path' => null,
            'role_images' => [],
        ]);
});

it('updates topology candidate artifacts with the same version when the CLI hash differs', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '2.0.0', sha256: str_repeat(
                'c',
                times: 64,
            )),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            manifestSource: 'topology-candidate',
            cliArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/candidate-build/orbit-linux-x64',
                    'sha256' => str_repeat('e', times: 64),
                ],
            ],
            roleImages: [
                'orbit-caddy' => 'caddy:2-alpine',
                'orbit-frankenphp' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-candidate-build@sha256:'
                    .str_repeat(
                        'f',
                        times: 64,
                    ),
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['app-dev-1'])
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(2)
        ->and(workload_updater_full_install_payload($shell, node: 'app-dev-1')['artifact_url'])
        ->toBe("http://gateway.test/api/update/artifacts/{$run->id}/cli/linux-amd64?token=fake")
        ->and(workload_updater_full_install_payload($shell, node: 'app-dev-1')['role_image_aliases'])
        ->toBe([
            [
                'source' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-candidate-build@sha256:'
                    .str_repeat(
                        'f',
                        times: 64,
                    ),
                'target' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            ],
        ])
        ->and(workloadUpdaterStepMessages($run))
        ->not
        ->toContain(
            ['workload.app-dev-1', 'done', 'Workload node app-dev-1 skipped: already up to date'],
        )
        ->and($node->fresh()->installed_cli?->source)
        ->toBe('topology-candidate')
        ->and($node->fresh()->installed_cli?->buildId)
        ->toBe('candidate-build');
});

it('uses the loaded archive tag as the topology candidate runtime alias source', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
        ]);
    $candidateImage = 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-candidate-build';
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workload_updater_snapshot_with_role_image_artifact(
            targetVersion: '2.0.0',
            manifestSource: 'topology-candidate',
            cliArtifacts: [],
            roleImages: [
                'orbit-caddy' => 'caddy:2-alpine',
                'orbit-frankenphp' => $candidateImage.'@sha256:'.str_repeat('f', times: 64),
            ],
            roleImageArtifacts: [
                'orbit-frankenphp' => [
                    'url' => 'https://artifacts.test/orbit-frankenphp-linux-amd64.tar',
                    'sha256' => str_repeat('e', times: 64),
                ],
            ],
        ),
    );

    app(WorkloadNodeUpdater::class)->update($run, $plan);

    $payload = workload_updater_full_install_payload($shell, node: 'app-dev-1');

    expect($payload['role_images'])
        ->toContain($candidateImage)
        ->and($payload['role_image_aliases'])
        ->toBe([[
            'source' => $candidateImage,
            'target' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        ]]);
});

it('updates macos workload nodes with darwin arm64 CLI artifacts and portable checksum verification', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'NMBP',
            'platform' => 'macos_26-5-1',
            'user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            manifestSource: 'topology-candidate',
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

    Http::fake([
        "http://{$node->wireguard_address}:9477/*" => Http::response('', 405),
    ]);

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'NMBP',
                'node' => 'NMBP',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(workload_updater_install_payload($shell, node: 'NMBP')['artifact_url'])
        ->toBe("http://gateway.test/api/update/artifacts/{$run->id}/cli/darwin-arm64?token=fake")
        ->and(workload_updater_install_payload($shell, node: 'NMBP')['bin_path'])
        ->toBe('/Users/nckrtl/.local/bin/orbit')
        ->and($shell->calls[0]['options']['metadata'])
        ->toBe([
            'ORBIT_OPERATION_ID' => $run->id,
            'ORBIT_BIN_PATH' => '/Users/nckrtl/.local/bin/orbit',
        ])
        ->and($node->fresh()->installed_cli?->platform)
        ->toBe('darwin-arm64')
        ->and($node->fresh()->installed_cli?->sha256)
        ->toBe(str_repeat('f', times: 64))
        ->and($node->fresh()->installed_cli?->artifactUrl)
        ->toBe('https://artifacts.orbit/releases/candidates/build/orbit-macos-arm64');
});

it('runs orbit doctor after a node update and reports the issue count in the done message', function (): void {
    $shell = new WorkloadUpdaterFakeShell(
        doctorIssues: ['app-dev-1' => 2, 'app-prod-1' => 0],
    );
    app()->instance(RunsInternalCommands::class, $shell);
    app()->instance(RemoteNodeDoctor::class, new WorkloadUpdaterFakeNodeDoctor(
        issues: ['app-dev-1' => 2, 'app-prod-1' => 0],
    ));

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'ubuntu_24-04']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])
        ->toBe('completed')
        ->and($results[0]['doctor_issues'])
        ->toBe(2)
        ->and($results[1]['doctor_issues'])
        ->toBe(0)
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(2)
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(
            ['workload.app-dev-1', 'done', 'Workload node app-dev-1 updated (2 issues)'],
            ['workload.app-prod-1', 'done', 'Workload node app-prod-1 updated'],
        );
});

it('keeps a workload update completed when advisory node doctor fails', function (): void {
    $shell = new WorkloadUpdaterFakeShell(
        doctorFailures: ['app-dev-1' => new RuntimeException('doctor timed out')],
    );
    app()->instance(RunsInternalCommands::class, $shell);
    app()->instance(RemoteNodeDoctor::class, new WorkloadUpdaterFakeNodeDoctor(
        failures: ['app-dev-1' => new RuntimeException('doctor timed out')],
    ));

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'orbit_path' => '/opt/orbit-app-dev',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'completed',
                'doctor_issues' => null,
            ],
        ])
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(2)
        ->and(workloadUpdaterStepMessages($run))
        ->toContain(
            ['workload.app-dev-1', 'done', 'Workload node app-dev-1 updated'],
        );
});

it('emits per-node sub-steps: installing cli, recording metadata, running doctor, done', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    app(WorkloadNodeUpdater::class)->update($run, $plan);

    $messages = workloadUpdaterStepMessages($run);

    expect($messages)->toContain(
        ['workload.app-dev-1', 'running', 'Installing CLI 2.0.0'],
        ['workload.app-dev-1', 'running', 'Recording installed CLI'],
        ['workload.app-dev-1', 'running', 'Running doctor'],
        ['workload.app-dev-1', 'done', 'Workload node app-dev-1 updated'],
    );

    // Sub-steps must arrive in order
    $nodeMessages = array_values(array_filter(
        $messages,
        fn (array $m): bool => $m[0] === 'workload.app-dev-1',
    ));

    $statuses = array_column($nodeMessages, 1);
    $texts = array_column($nodeMessages, 2);

    $downloadIndex = array_search('Installing CLI 2.0.0', $texts, true);
    $replaceIndex = array_search('Recording installed CLI', $texts, true);
    $doctorIndex = array_search('Running doctor', $texts, true);
    $doneIndex = array_search('done', $statuses, true);

    expect($downloadIndex)
        ->toBeLessThan($replaceIndex)
        ->and($replaceIndex)
        ->toBeLessThan($doctorIndex)
        ->and($doctorIndex)
        ->toBeLessThan($doneIndex);
});

it('emits skipped sub-step (no download/replace/doctor) for a node already on the desired CLI artifact', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'installed_cli' => workloadUpdaterInstalledCliArtifact(version: '2.0.0'),
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    app(WorkloadNodeUpdater::class)->update($run, $plan);

    $messages = workloadUpdaterStepMessages($run);
    $nodeMessages = array_values(array_filter(
        $messages,
        fn (array $m): bool => $m[0] === 'workload.app-dev-1',
    ));
    $texts = array_column($nodeMessages, 2);

    expect($texts)
        ->not->toContain('Downloading 2.0.0')->and($texts)
        ->not->toContain('Replacing cli binary')->and($texts)
        ->not->toContain('Running doctor');
});

it('keeps a non-zero doctor issue count from failing the node update', function (): void {
    $shell = new WorkloadUpdaterFakeShell(doctorIssues: ['app-dev-1' => 5]);
    app()->instance(RunsInternalCommands::class, $shell);
    app()->instance(RemoteNodeDoctor::class, new WorkloadUpdaterFakeNodeDoctor(
        issues: ['app-dev-1' => 5],
    ));

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])->toBe('completed')->and($results[0]['doctor_issues'])->toBe(5);
});

it('continues updating later workload nodes when one remote update fails', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteShellResult(exitCode: 12, stdout: '', stderr: 'download failed', durationMs: 10),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'ubuntu_24-04']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)
        ->toMatchArray([
            [
                'target' => 'app-dev-1',
                'node' => 'app-dev-1',
                'roles' => ['app-dev'],
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => 'download failed',
            ],
            [
                'target' => 'app-prod-1',
                'node' => 'app-prod-1',
                'roles' => ['app-prod'],
                'status' => 'completed',
                'doctor_issues' => 0,
            ],
        ])
        ->and(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['app-dev-1', 'app-prod-1'])
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(1)
        ->and($shell->updateScriptCallsFor('app-prod-1'))
        ->toBe(2)
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);
});

it('fails the runner workload phase with target results when any workload update fails', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteShellResult(exitCode: 12, stdout: '', stderr: 'download failed', durationMs: 10),
    ]);
    app()->instance(RunsInternalCommands::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterFailIfCalledFleetVerifier);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'ubuntu_24-04']);
    app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(WorkloadNodeUpdateFailed::class, 'One or more workload nodes failed to update.');

    $run->refresh();
    $error = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->firstOrFail();

    expect($run->status)
        ->toBe(OperationStatus::Failed)
        ->and($run->error)
        ->toMatchArray([
            'code' => 'workload_update_failed',
            'message' => 'One or more workload nodes failed to update.',
            'data' => [
                'failed_targets' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'roles' => ['app-dev'],
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                ],
                'target_results' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'roles' => ['app-dev'],
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                    [
                        'target' => 'app-prod-1',
                        'node' => 'app-prod-1',
                        'roles' => ['app-prod'],
                        'status' => 'completed',
                        'doctor_issues' => 0,
                    ],
                ],
            ],
        ])
        ->and($error->payload)
        ->toMatchArray([
            'exit_code' => 1,
            'data' => [
                'code' => 'workload_update_failed',
                'failed_targets' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'roles' => ['app-dev'],
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                ],
                'target_results' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'roles' => ['app-dev'],
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                    [
                        'target' => 'app-prod-1',
                        'node' => 'app-prod-1',
                        'roles' => ['app-prod'],
                        'status' => 'completed',
                        'doctor_issues' => 0,
                    ],
                ],
            ],
        ])
        ->and(workloadUpdaterStepEvents($run))
        ->toContain(
            ['workload.app-dev-1', 'fail'],
            ['workload.app-prod-1', 'done'],
            ['workload-nodes', 'fail'],
        )
        ->and(workloadUpdaterStepEvents($run))
        ->not->toContain(['verification', 'running']);
});

it('fails the update operation when a workload node lease is already held', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterNoopFleetVerifier);

    $run = workloadUpdaterRun();
    $conflictingNode = Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'ubuntu_24-04']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());
    $otherRun = workloadUpdaterRun();

    $conflictingLease = app(UpdateLeaseManager::class)->acquire(
        resourceType: 'node',
        resourceKey: $conflictingNode->name,
        operationRun: $otherRun,
        ownerToken: 'other-owner',
        ttlSeconds: 300,
    );

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(UpdateLeaseConflict::class);

    $error = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->firstOrFail();

    expect($shell->updatedNodes())
        ->toBeEmpty()
        ->and($run->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($run->error)
        ->toMatchArray([
            'code' => 'update.node_locked',
            'message' =>
                'Update resource [node:app-dev-1] is already leased by operation ['
                    .$otherRun->id
                    .'] until '
                    .$conflictingLease->expires_at->toIso8601String()
                    .'.',
            'data' => [
                'resource' => 'node:app-dev-1',
                'resource_type' => 'node',
                'resource_key' => 'app-dev-1',
                'lease_id' => $conflictingLease->id,
                'conflicting_operation_id' => $otherRun->id,
                'expires_at' => $conflictingLease->expires_at->toIso8601String(),
            ],
        ])
        ->and($error->payload)
        ->toMatchArray([
            'exit_code' => 1,
            'data' => [
                'code' => 'update.node_locked',
                'resource' => 'node:app-dev-1',
                'resource_type' => 'node',
                'resource_key' => 'app-dev-1',
                'lease_id' => $conflictingLease->id,
                'conflicting_operation_id' => $otherRun->id,
                'expires_at' => $conflictingLease->expires_at->toIso8601String(),
            ],
        ])
        ->and(workloadUpdaterStepEvents($run))
        ->toContain(
            ['workload-nodes', 'running'],
            ['workload.app-dev-1', 'running'],
            ['workload.app-dev-1', 'fail'],
            ['workload-nodes', 'fail'],
        )
        ->and(workloadUpdaterStepEvents($run))
        ->not
        ->toContain(['workload.app-prod-1', 'running'])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->pluck('resource_key')->all())
        ->toBe(['app-dev-1']);
});

it('is invoked by the default update runner pipeline while the fleet lease is active', function (): void {
    config()->set('app.version', '2.0.0');

    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RunsInternalCommands::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterNoopFleetVerifier);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'ubuntu_24-04']);
    app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    app(UpdateRunner::class)->run($run->id);

    expect(array_values(array_unique($shell->updatedNodes())))
        ->toBe(['app-dev-1'])
        ->and($shell->versionProbeCallsFor('app-dev-1'))
        ->toBe(0)
        ->and($shell->updateScriptCallsFor('app-dev-1'))
        ->toBe(2)
        ->and($shell->activeLeases)
        ->toBe([
            'app-dev-1' => ['fleet:update-all', 'node:app-dev-1'],
        ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);
});

function workloadUpdaterRun(?int $callerNodeId = null): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        callerNodeId: $callerNodeId,
    );
}

/**
 * @return list<array{0: string, 1: string}>
 */
function workloadUpdaterStepEvents(OperationRun $run): array
{
    return $run
        ->events()
        ->where('event_type', 'step')
        ->get()
        ->map(fn (OperationEvent $event): array => [$event->payload['key'], $event->payload['status']])
        ->all();
}

/**
 * @return list<array{0: string, 1: string, 2: string|null}>
 */
function workloadUpdaterStepMessages(OperationRun $run): array
{
    return $run
        ->events()
        ->where('event_type', 'step')
        ->get()
        ->map(fn (OperationEvent $event): array => [
            $event->payload['key'],
            $event->payload['status'],
            $event->payload['message'] ?? null,
        ])
        ->all();
}

class WorkloadUpdaterNoopGatewayUpdater extends GatewayServiceUpdater
{
    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class WorkloadUpdaterNoopFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class WorkloadUpdaterFailIfCalledFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        throw new RuntimeException('Fleet verification should not run after a workload update failure.');
    }
}

/**
 * @return array{url: string, sha256: string, signature: string, version: string, platform: string, architecture: string}
 */
function workload_updater_desktop_artifact(): array
{
    return [
        'url' => 'https://artifacts.test/Orbit.app.tar.gz',
        'sha256' => str_repeat('c', times: 64),
        'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
        'version' => '1.2.3',
        'platform' => 'darwin',
        'architecture' => 'arm64',
    ];
}

function workload_updater_darwin_snapshot(): OperationUpdatePlanSnapshot
{
    return workload_updater_darwin_plan([
        'darwin-arm64' => [
            'url' => 'https://artifacts.test/orbit-agent-macos-arm64',
            'sha256' => str_repeat('e', times: 64),
        ],
    ]);
}

function workload_updater_darwin_snapshot_without_agent(): OperationUpdatePlanSnapshot
{
    return workload_updater_darwin_plan([]);
}

/**
 * @param  array<string, array{url: string, sha256: string}>  $agentArtifacts
 */
function workload_updater_darwin_plan(array $agentArtifacts): OperationUpdatePlanSnapshot
{
    $cliArtifacts = [
        'darwin-arm64' => [
            'url' => 'https://artifacts.test/orbit-macos-arm64',
            'sha256' => str_repeat('b', times: 64),
        ],
    ];
    $desktop = workload_updater_desktop_artifact();
    $base = workloadUpdaterSnapshot(
        cliArtifacts: $cliArtifacts,
        agentArtifacts: $agentArtifacts,
    );

    return new OperationUpdatePlanSnapshot(
        targetVersion: $base->targetVersion,
        gatewayImage: $base->gatewayImage,
        manifestSource: $base->manifestSource,
        manifestVersion: $base->manifestVersion,
        manifestSnapshot: [
            ...$base->manifestSnapshot,
            'desktop_artifacts' => [
                'darwin-arm64' => $desktop,
            ],
        ],
        cliArtifacts: $base->cliArtifacts,
        agentArtifacts: $base->agentArtifacts,
        desktopArtifacts: [
            'darwin-arm64' => $desktop,
        ],
        roleImages: $base->roleImages,
    );
}

function workloadUpdaterSnapshot(
    string $targetVersion = '1.2.3',
    string $manifestSource = 'github-release',
    array $cliArtifacts = [],
    array $agentArtifacts = [],
    array $roleImages = [],
): OperationUpdatePlanSnapshot {
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $cliArtifacts = $cliArtifacts === []
        ? [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
                'sha256' => str_repeat('b', times: 64),
            ],
        ] : $cliArtifacts;
    $roleImages = $roleImages === []
        ? [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ] : $roleImages;

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: $manifestSource,
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => $manifestSource,
            ...($manifestSource === 'topology-candidate' ? ['build_id' => 'candidate-build'] : []),
            'images' => [
                'gateway' => $gatewayImage,
            ],
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
 * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
 * @param  array<string, string>  $roleImages
 * @param  array<string, array{url: string, sha256: string}>  $roleImageArtifacts
 */
function workload_updater_snapshot_with_role_image_artifact(
    string $targetVersion,
    array $cliArtifacts,
    array $roleImages,
    string $manifestSource = 'github-release',
    array $roleImageArtifacts = [],
): OperationUpdatePlanSnapshot {
    $snapshot = workloadUpdaterSnapshot(
        targetVersion: $targetVersion,
        manifestSource: $manifestSource,
        cliArtifacts: $cliArtifacts,
        roleImages: $roleImages,
    );
    $roleImageArtifacts = $roleImageArtifacts !== []
        ? $roleImageArtifacts
        : [
            'orbit-websocket' => [
                'url' => 'https://artifacts.test/orbit-reverb-linux-amd64.tar',
                'sha256' => str_repeat('f', times: 64),
            ],
        ];

    return new OperationUpdatePlanSnapshot(
        targetVersion: $snapshot->targetVersion,
        gatewayImage: $snapshot->gatewayImage,
        manifestSource: $snapshot->manifestSource,
        manifestVersion: $snapshot->manifestVersion,
        manifestSnapshot: [
            ...$snapshot->manifestSnapshot,
            'role_image_artifacts' => $roleImageArtifacts,
        ],
        cliArtifacts: $snapshot->cliArtifacts,
        agentArtifacts: $snapshot->agentArtifacts,
        roleImages: $snapshot->roleImages,
    );
}

function workloadUpdaterInstalledCliArtifact(
    string $version = '1.2.3',
    string $sha256 = '',
): InstalledCliArtifact {
    return InstalledCliArtifact::record(
        version: $version,
        platform: 'linux-amd64',
        sha256: $sha256 !== '' ? $sha256 : str_repeat('b', times: 64),
        source: 'github-release',
        buildId: null,
        artifactUrl: "https://github.com/hardimpactdev/orbit/releases/download/v{$version}/orbit-linux-amd64",
        installedPath: '/home/orbit/orbit/bin/orbit-binary',
        operationRunId: (string) Str::uuid(),
    );
}

final class WorkloadUpdaterFakeArtifactRelay extends GatewayCliArtifactRelay
{
    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    #[Override]
    public function artifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): array
    {
        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (
            ! is_array($artifact)
            || ! is_string($artifact['sha256'] ?? null)
            || ! is_string($artifact['url'] ?? null)
        ) {
            throw new RuntimeException("Missing test artifact for [{$platform}].");
        }

        return [
            'url' => "http://gateway.test/api/update/artifacts/{$operationRun->id}/cli/{$platform}?token=fake",
            'sha256' => $artifact['sha256'],
            'source_url' => $artifact['url'],
        ];
    }

    /**
     * @return array{url: string, sha256: string, source_url: string}|null
     */
    #[Override]
    public function agentArtifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): ?array
    {
        $agentArtifacts = $plan->agent_artifacts ?? [];
        $artifact = $agentArtifacts[$platform] ?? null;

        if ($artifact === null) {
            return null;
        }

        if (
            ! is_array($artifact)
            || ! is_string($artifact['sha256'] ?? null)
            || ! is_string($artifact['url'] ?? null)
        ) {
            throw new RuntimeException("Missing test agent artifact for [{$platform}].");
        }

        return [
            'url' => "http://gateway.test/api/update/artifacts/{$operationRun->id}/agent/{$platform}?token=fake",
            'sha256' => $artifact['sha256'],
            'source_url' => $artifact['url'],
        ];
    }

    /**
     * @return array{url: string, sha256: string, source_url: string, signature: string, version: string, platform: string, architecture: string}|null
     */
    #[Override]
    public function desktopArtifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): ?array
    {
        $desktopArtifacts = $plan->desktop_artifacts ?? [];
        $artifact = $desktopArtifacts[$platform] ?? null;

        if ($artifact === null) {
            return null;
        }

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
            || ! is_string($artifact['signature'] ?? null)
            || ! is_string($artifact['version'] ?? null)
            || ! is_string($artifact['platform'] ?? null)
            || ! is_string($artifact['architecture'] ?? null)
        ) {
            throw new RuntimeException("Missing test desktop artifact for [{$platform}].");
        }

        return [
            'url' => "http://gateway.test/api/update/artifacts/{$operationRun->id}/desktop/{$platform}?token=fake",
            'sha256' => $artifact['sha256'],
            'source_url' => $artifact['url'],
            'signature' => $artifact['signature'],
            'version' => $artifact['version'],
            'platform' => $artifact['platform'],
            'architecture' => $artifact['architecture'],
        ];
    }

    #[Override]
    public function stage(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }

    #[Override]
    public function cleanup(OperationRun $operationRun): void
    {
        //
    }
}

readonly class WorkloadUpdaterFakeCa extends OrbitCaService
{
    #[Override]
    public function rootCert(): string
    {
        return "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n";
    }
}

final class WorkloadUpdaterFakeNodeDoctor extends RemoteNodeDoctor
{
    /**
     * @var list<array{node: string, operation_run_id: int|string}>
     */
    public array $calls = [];

    /**
     * @param  array<string, int>  $issues
     * @param  array<string, Throwable>  $failures
     */
    public function __construct(
        private array $issues = [],
        private array $failures = [],
    ) {}

    #[Override]
    public function issues(Node $node, OperationRun $operationRun): ?int
    {
        $this->calls[] = [
            'node' => $node->name,
            'operation_run_id' => $operationRun->id,
        ];

        if (isset($this->failures[$node->name])) {
            return null;
        }

        return $this->issues[$node->name] ?? 0;
    }
}

function workloadUpdaterIsVersionProbe(string $script): bool
{
    return in_array(
        $script,
        [
            'orbit --version',
            'orbit --version --local',
            'orbit --version --local --json',
        ],
        true,
    );
}

function workloadUpdaterVersionStdout(string $version, string $script): string
{
    if ($script === 'orbit --version --local --json') {
        return json_encode([
            'success' => [
                'data' => [
                    'version' => $version,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    return "Version       {$version}\n";
}

/**
 * @return array<string, mixed>
 */
function workload_updater_install_payload(WorkloadUpdaterFakeShell $shell, string $node): array
{
    /** @var mixed $payload */
    $payload = json_decode($shell->scriptFor($node), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    foreach (array_keys($payload) as $key) {
        if (! is_string($key)) {
            return [];
        }
    }

    /** @var array<string, mixed> $payload */
    return $payload;
}

/**
 * @return array<string, mixed>
 */
function workload_updater_full_install_payload(WorkloadUpdaterFakeShell $shell, string $node): array
{
    $payloads = workload_updater_install_payloads($shell, node: $node);

    if ($payloads === []) {
        return [];
    }

    return $payloads[array_key_last($payloads)] ?? [];
}

/**
 * @return list<array<string, mixed>>
 */
function workload_updater_install_payloads(WorkloadUpdaterFakeShell $shell, string $node): array
{
    return array_values(array_filter(array_map(
        static function (array $call) use ($node): ?array {
            if (
                $call['node'] !== $node
                || workloadUpdaterIsVersionProbe($call['script'])
                || str_contains($call['script'], 'doctor')
            ) {
                return null;
            }

            /** @var mixed $payload */
            $payload = json_decode((string) ($call['options']['input'] ?? ''), associative: true);

            if (! is_array($payload)) {
                return null;
            }

            /** @var array<string, mixed> $payload */
            return $payload;
        },
        $shell->calls,
    )));
}

/** @mago-expect lint:cyclomatic-complexity */
final class WorkloadUpdaterFakeShell implements RemoteShell, RunsInternalCommands
{
    /**
     * @var list<array{node: string, script: string, command_options?: array<int|string, mixed>, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * Active leases captured at the moment the remote update script runs.
     *
     * @var array<string, list<string>>
     */
    public array $activeLeases = [];

    /**
     * @param  array<string, RemoteShellResult|RemoteLocalExecutorTransportFailed|list<RemoteShellResult|RemoteLocalExecutorTransportFailed>>  $failures  Keyed by node name; applied to the remote update script call.
     * @param  array<string, string>  $versions  Probed version output keyed by node name (defaults to the target).
     * @param  array<string, int>  $doctorIssues  Per-node doctor issue counts keyed by node name.
     * @param  array<string, Throwable>  $doctorFailures  Per-node doctor exceptions keyed by node name.
     */
    public function __construct(
        private array $failures = [],
        private array $versions = [],
        private array $doctorIssues = [],
        private array $doctorFailures = [],
        private string $defaultVersion = '0.0.0',
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        new RemoteShellMetadata()->prologue($options['metadata'] ?? []);

        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        if (workloadUpdaterIsVersionProbe($script)) {
            $version = $this->versions[$node->name] ?? $this->defaultVersion;

            return new RemoteShellResult(
                exitCode: 0,
                stdout: workloadUpdaterVersionStdout($version, $script),
                stderr: '',
                durationMs: 5,
            );
        }

        if (str_contains($script, 'doctor')) {
            if (isset($this->doctorFailures[$node->name])) {
                throw $this->doctorFailures[$node->name];
            }

            $issues = $this->doctorIssues[$node->name] ?? 0;

            return new RemoteShellResult(
                exitCode: 0,
                stdout: implode("\n", [
                    json_encode(['event' => 'doctor.node.start'], JSON_THROW_ON_ERROR),
                    json_encode([
                        'event' => $issues > 0 ? 'event:error' : 'doctor.node.done',
                        'data' => [
                            'doctor' => [
                                'summary' => [
                                    'issues' => $issues,
                                ],
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ]),
                stderr: '',
                durationMs: 8,
            );
        }

        $this->activeLeases[$node->name] = UpdateLease::query()
            ->whereNotNull('active_resource_key')
            ->orderBy('id')
            ->get()
            ->map(fn (UpdateLease $lease): string => "{$lease->resource_type}:{$lease->resource_key}")
            ->all();

        return $this->updateResultFor($node);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    #[Override]
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        new RemoteShellMetadata()->prologue($transportOptions['metadata'] ?? []);

        $this->calls[] = [
            'node' => $node->name,
            'script' => $commandName,
            'command_options' => $commandOptions,
            'options' => $transportOptions,
        ];

        $this->activeLeases[$node->name] = UpdateLease::query()
            ->whereNotNull('active_resource_key')
            ->orderBy('id')
            ->get()
            ->map(fn (UpdateLease $lease): string => "{$lease->resource_type}:{$lease->resource_key}")
            ->all();

        return $this->updateResultFor($node);
    }

    private function updateResultFor(Node $node): RemoteShellResult
    {
        $failure = $this->failures[$node->name] ?? null;

        if ($failure instanceof RemoteShellResult) {
            return $failure;
        }

        if ($failure instanceof RemoteLocalExecutorTransportFailed) {
            throw $failure;
        }

        if (is_array($failure) && $failure !== []) {
            $result = array_shift($failure);
            $this->failures[$node->name] = $failure;

            if ($result instanceof RemoteShellResult) {
                return $result;
            }

            if ($result instanceof RemoteLocalExecutorTransportFailed) {
                throw $result;
            }
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->successfulInstallStdout(),
            stderr: '',
            durationMs: 20,
        );
    }

    private function successfulInstallStdout(): string
    {
        $lastCallKey = array_key_last($this->calls);
        $call = $lastCallKey === null ? null : $this->calls[$lastCallKey];
        $input = is_array($call) && is_string($call['options']['input'] ?? null)
            ? $call['options']['input']
            : '{}';
        /** @var mixed $payload */
        $payload = json_decode($input, associative: true);
        $agentArtifact = is_array($payload) ? $payload['agent_artifact'] ?? null : null;
        $data = [
            'installed' => true,
        ];

        if (is_array($agentArtifact)) {
            $data['agent_installed'] = true;
            $data['agent_bin_path'] = $agentArtifact['bin_path'] ?? null;
        }

        return json_encode([
            'success' => [
                'data' => $data,
                'meta' => [],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function scriptFor(string $node): string
    {
        foreach ($this->calls as $call) {
            if (
                $call['node'] === $node
                && ! workloadUpdaterIsVersionProbe($call['script'])
                && ! str_contains($call['script'], 'doctor')
            ) {
                return is_string($call['options']['input'] ?? null) ? $call['options']['input'] : $call['script'];
            }
        }

        throw new RuntimeException("No update script recorded for [{$node}].");
    }

    /**
     * @return list<string>
     */
    public function scriptsFor(string $node): array
    {
        return array_values(array_map(
            fn (array $call): string => is_string($call['options']['input'] ?? null)
                ? $call['options']['input']
                : $call['script'],
            array_filter($this->calls, fn (array $call): bool => $call['node'] === $node),
        ));
    }

    /**
     * @return list<string>
     */
    public function updatedNodes(): array
    {
        $nodes = [];

        foreach ($this->calls as $call) {
            if (! workloadUpdaterIsVersionProbe($call['script']) && ! str_contains($call['script'], 'doctor')) {
                $nodes[] = $call['node'];
            }
        }

        return $nodes;
    }

    public function versionProbeCallsFor(string $node): int
    {
        return count(array_filter(
            $this->calls,
            fn (array $call): bool => $call['node'] === $node && workloadUpdaterIsVersionProbe($call['script']),
        ));
    }

    public function updateScriptCallsFor(string $node): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => (
                $call['node'] === $node
                && ! workloadUpdaterIsVersionProbe($call['script'])
                && ! str_contains($call['script'], 'doctor')
            ),
        ));
    }
}
