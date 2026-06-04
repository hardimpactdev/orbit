<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessesProbe;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new ProcessesProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('process');
        expect($this->probe->label())->toBe('Processes');
    });

    it('returns an empty foundation snapshot before live runtime probing is added', function (): void {
        $process = new Process(['name' => 'vite']);

        $snapshot = $this->probe->introspect($process);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('runtime backend availability', function (): void {
    it('introspects supervisor runtime backend availability on the owner app node', function (): void {
        $app = processableApp();
        $process = processFor($app, ['name' => 'vite']);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit_{$app->name}_main_vite\t1\t1\t1\t1\n__notifier\t1\t1\t1\t1\t1\n__extra\torbit_docs_old_queue\n", stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);

        expect($snapshot->get('vite'))->toMatchArray([
            'runtime_backend_available' => true,
            'runtime_backend_exit_code' => 0,
            'runtime_backend_output' => 'supervisor OK',
        ]);
        expect($shell->scripts[0])->toBe('command -v supervisorctl >/dev/null 2>&1 && sudo supervisorctl version >/dev/null 2>&1');
        expect($shell->scripts[1])->toContain('php -r');
        expect(json_decode((string) ($shell->options[1]['input'] ?? ''), true))->toHaveKeys(['units', 'event_notifier']);
        expect($shell->nodes[0]->is($app->node))->toBeTrue();
        expect($snapshot->get('vite')['runtime_units']["orbit_{$app->name}_main_vite"])->toMatchArray([
            'config_exists' => true,
            'config_matches' => true,
            'restart_policy_matches' => true,
            'environment_matches' => true,
        ]);
        expect($snapshot->get('vite')['runtime_unit_extras'])->toBe(['orbit_docs_old_queue']);
    });

    it('detects unavailable supervisor runtime backends and leaves downstream checks to later layers', function (): void {
        $app = processableApp();
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => false,
                'runtime_backend_exit_code' => 127,
                'runtime_backend_output' => 'missing supervisorctl',
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_backend_unavailable')?->kind)->toBe(DriftKind::Unverifiable);
        expect(issue($drift, 'process.runtime_backend_unavailable')?->detail)->toMatchArray([
            'node' => $app->node->name,
            'exit_code' => 127,
            'output' => 'missing supervisorctl',
        ]);
    });

    it('does not report runtime backend drift without an owner app node snapshot', function (): void {
        $process = new Process(['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => false,
            ],
        ]));

        expect(issue($drift, 'process.runtime_backend_unavailable'))->toBeNull();
    });
});

describe('WireGuard self-route diagnostics', function (): void {
    it('reports unavailable Linux self-routes for node-owned service process endpoints', function (): void {
        $node = Node::factory()->database()->create([
            'name' => 'database-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.7',
        ]);
        $process = Process::factory()->forOwner($node)->create([
            'name' => 'redis',
            'command' => 'redis-server --appendonly yes',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'definition' => 'redis',
                'endpoint' => [
                    'name' => 'redis',
                    'kind' => 'tcp',
                    'host' => '10.6.0.7',
                    'port' => 6379,
                ],
            ],
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "10.6.0.7 dev wg-orbit src 10.6.0.2\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.owner_app_invalid'))->toBeNull()
            ->and(issue($drift, 'process.wireguard_self_route_unavailable')?->kind)->toBe(DriftKind::Unverifiable)
            ->and(issue($drift, 'process.wireguard_self_route_unavailable')?->detail)->toMatchArray([
                'process' => 'redis',
                'node' => 'database-1',
                'endpoint' => 'redis',
                'host' => '10.6.0.7',
                'port' => 6379,
                'wireguard_address' => '10.6.0.7',
                'reason' => 'self_route_missing',
                'message' => 'Linux node does not route its own WireGuard address locally.',
            ])
            ->and($shell->scripts)->toBe(["ip route get '10.6.0.7'"]);
    });

    it('does not report process self-route drift when Linux routes its own WireGuard address locally', function (): void {
        $node = Node::factory()->database()->create([
            'name' => 'database-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.7',
        ]);
        $process = Process::factory()->forOwner($node)->create([
            'name' => 'redis',
            'command' => 'redis-server --appendonly yes',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'endpoint' => [
                    'name' => 'redis',
                    'kind' => 'tcp',
                    'host' => '10.6.0.7',
                    'port' => 6379,
                ],
            ],
        ]);
        app()->instance(RemoteShell::class, new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "local 10.6.0.7 dev lo src 10.6.0.7\n", stderr: '', durationMs: 1),
        ]));

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.wireguard_self_route_unavailable'))->toBeNull();
    });

    it('reports macOS as unsupported for process self-route diagnostics without route commands', function (): void {
        $node = Node::factory()->database()->create([
            'name' => 'database-1',
            'platform' => 'macos_15-4',
            'wireguard_address' => '10.6.0.7',
        ]);
        $process = Process::factory()->forOwner($node)->create([
            'name' => 'redis',
            'command' => 'redis-server --appendonly yes',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'endpoint' => [
                    'name' => 'redis',
                    'kind' => 'tcp',
                    'host' => '10.6.0.7',
                    'port' => 6379,
                ],
            ],
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.wireguard_self_route_unavailable')?->detail)->toMatchArray([
            'platform' => 'macos_15-4',
            'reason' => 'unsupported_platform',
            'message' => NodeWireGuardSelfRouteProbe::UnsupportedMessage,
        ])
            ->and($shell->scripts)->toBe([]);
    });
});

describe('lifecycle event notifier reality', function (): void {
    it('introspects crash event notifier material for crash-reporting processes', function (): void {
        LocalGatewaySettings::current()->fill(['gateway_url' => 'https://10.6.0.2'])->save();

        $app = processableApp();
        $process = processFor($app, [
            'name' => 'vite',
            'crash_notification' => ProcessCrashNotification::AgentIde,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit_{$app->name}_main_vite\t1\t1\t1\t1\n__notifier\t1\t1\t1\t1\t1\n", stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);

        expect($snapshot->get('vite')['event_notifier'])->toMatchArray([
            'script_exists' => true,
            'script_executable' => true,
            'script_matches' => true,
            'gateway_endpoint_exists' => true,
            'gateway_endpoint_matches' => true,
        ]);
    });

    it('detects missing crash event notifier material for crash-reporting processes', function (): void {
        $app = processableApp();
        $process = processFor($app, [
            'name' => 'vite',
            'crash_notification' => ProcessCrashNotification::AgentIde,
        ]);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'event_notifier' => [
                    'script_exists' => false,
                    'script_executable' => false,
                    'script_matches' => false,
                    'gateway_endpoint_exists' => false,
                    'gateway_endpoint_matches' => false,
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.event_notifier_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects divergent crash event notifier material for crash-reporting processes', function (): void {
        $app = processableApp();
        $process = processFor($app, [
            'name' => 'vite',
            'crash_notification' => ProcessCrashNotification::AgentIde,
        ]);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'event_notifier' => [
                    'script_exists' => true,
                    'script_executable' => true,
                    'script_matches' => false,
                    'gateway_endpoint_exists' => true,
                    'gateway_endpoint_matches' => true,
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.event_notifier_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('does not require notifier material when crash reporting is disabled', function (): void {
        $app = processableApp();
        $process = processFor($app, [
            'name' => 'vite',
            'crash_notification' => ProcessCrashNotification::None,
        ]);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'event_notifier' => [
                    'script_exists' => false,
                    'gateway_endpoint_exists' => false,
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.event_notifier_missing'))->toBeNull();
        expect(issue($drift, 'process.event_notifier_mismatch'))->toBeNull();
    });
});

describe('stale supervisor program reality', function (): void {
    it('detects stale Orbit-owned supervisor programs without active gateway process intent', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'runtime_unit_extras' => ['orbit_docs_old_queue'],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_extra')?->kind)->toBe(DriftKind::Extra);
        expect(issue($drift, 'process.runtime_unit_extra')?->detail)->toMatchArray([
            'runtime_unit' => 'orbit_docs_old_queue',
            'expected_path' => '/etc/supervisor/conf.d/orbit_docs_old_queue.conf',
        ]);
    });

    it('skips stale supervisor program checks while runtime backend is unavailable', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => false,
                'runtime_unit_extras' => ['orbit_docs_old_queue'],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_extra'))->toBeNull();
    });
});

describe('supervisor program reality', function (): void {
    it('detects missing supervisor programs for expected runtime contexts', function (): void {
        $app = processableApp(['name' => 'docs']);
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature-docs',
                'path' => "{$app->path}/.worktrees/feature-docs",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit_docs_main_vite' => [
                        'config_exists' => true,
                        'config_matches' => true,
                        'restart_policy_matches' => true,
                        'environment_matches' => true,
                    ],
                    'orbit_docs_feature-docs_vite' => [
                        'config_exists' => false,
                        'config_matches' => false,
                        'restart_policy_matches' => false,
                        'environment_matches' => false,
                    ],
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'process.runtime_unit_missing')?->detail)->toMatchArray([
            'runtime_unit' => 'orbit_docs_feature-docs_vite',
            'expected' => '/etc/supervisor/conf.d/orbit_docs_feature-docs_vite.conf',
        ]);
    });

    it('detects supervisor program content mismatches', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit_docs_main_vite' => [
                        'config_exists' => true,
                        'config_matches' => false,
                        'restart_policy_matches' => true,
                        'environment_matches' => true,
                    ],
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('skips supervisor program checks while runtime backend is unavailable', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => false,
                'runtime_units' => [
                    'orbit_docs_main_vite' => [
                        'config_exists' => false,
                        'config_matches' => false,
                        'restart_policy_matches' => false,
                        'environment_matches' => false,
                    ],
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_missing'))->toBeNull();
        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });
});

describe('supervisor program restart and environment reality', function (): void {
    it('detects supervisor restart policy mismatches separately from generic unit drift', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit_docs_main_vite' => [
                        'config_exists' => true,
                        'config_matches' => false,
                        'restart_policy_matches' => false,
                        'environment_matches' => true,
                    ],
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.restart_policy_mismatch')?->kind)->toBe(DriftKind::Divergent);
        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });

    it('detects supervisor runtime environment mismatches separately from generic unit drift', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit_docs_main_vite' => [
                        'config_exists' => true,
                        'config_matches' => false,
                        'restart_policy_matches' => true,
                        'environment_matches' => false,
                    ],
                ],
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_environment_mismatch')?->kind)->toBe(DriftKind::Divergent);
        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });
});

describe('registry intent', function (): void {
    it('passes complete process records with eligible owner apps and runtime contexts', function (): void {
        $app = processableApp(['name' => 'docs']);
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature-docs',
                'path' => "{$app->path}/.worktrees/feature-docs",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete process records', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'node_id' => $app->node_id,
            'owner_type' => $app->getMorphClass(),
            'owner_id' => $app->id,
            'name' => 'vite',
            'command' => '',
            'restart_policy' => ProcessRestartPolicy::Never->value,
            'crash_notification' => ProcessCrashNotification::None->value,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('process');
        expect($drift[0]->key)->toBe('process.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects unsupported restart policy intent', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'node_id' => $app->node_id,
            'owner_type' => $app->getMorphClass(),
            'owner_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'sometimes',
            'crash_notification' => ProcessCrashNotification::None->value,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects unsupported crash notification intent', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'node_id' => $app->node_id,
            'owner_type' => $app->getMorphClass(),
            'owner_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Never->value,
            'crash_notification' => 'pager',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });
});

describe('owner app eligibility', function (): void {
    it('requires an owner app on an active app node', function (callable $createNode): void {
        $node = $createNode();
        $app = App::factory()->for($node, 'node')->create();
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.owner_app_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway owner node' => [fn (): Node => Node::factory()->gateway()->create(['status' => 'active'])],
        'inactive app owner node' => [fn (): Node => Node::factory()->appDev()->create(['status' => 'inactive'])],
    ]);
});

describe('runtime context expansion', function (): void {
    it('detects runtime contexts that cannot produce safe supervisor program names', function (): void {
        $app = processableApp(['name' => 'Docs_App']);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.runtime_context_unresolved')?->kind)->toBe(DriftKind::Unverifiable);
    });

    it('detects invalid workspace runtime context identity', function (): void {
        $app = processableApp();
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'Feature_App',
                'path' => "{$app->path}/.worktrees/feature",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.runtime_context_unresolved')?->kind)->toBe(DriftKind::Unverifiable);
    });
});

describe('docker runtime probe scope', function (): void {
    it('matches process-backed app runtime rows against the managed app runtime container label', function (): void {
        $app = processableApp([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        $container = app(AppRuntimeContainerRenderer::class)->render($app);
        $process = processFor($app, [
            'name' => 'frankenphp-docs',
            'command' => 'frankenphp',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'container_name' => 'orbit-app-docs',
                'container_spec_hash' => $container->specHash(),
                'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
            ],
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'running'],
                'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => $container->specHash()]],
            ], JSON_THROW_ON_ERROR), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);

        expect($shell->scripts[0])->toContain("docker container inspect --format '{{json .}}' 'orbit-app-docs'")
            ->and($snapshot->get('frankenphp-docs'))->toMatchArray([
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit-app-docs' => [
                        'config_exists' => true,
                        'config_matches' => true,
                        'container_state' => 'running',
                    ],
                ],
            ]);
    });

    it('matches process-backed workspace runtime rows only against the owning workspace container label', function (): void {
        $app = processableApp([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        $workspace = Workspace::factory()->for($app)->create([
            'name' => 'feature-a',
            'path' => '/home/orbit/apps/docs/.worktrees/feature-a',
            'php_version' => '8.5',
        ]);
        Workspace::factory()->for($app)->create([
            'name' => 'other',
            'path' => '/home/orbit/apps/docs/.worktrees/other',
            'php_version' => '8.5',
        ]);
        $container = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace);
        $process = Process::factory()->forOwner($workspace)->create([
            'name' => 'frankenphp-docs-feature-a',
            'command' => 'frankenphp',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'container_name' => 'orbit-ws-docs-feature-a',
                'container_spec_hash' => $container->specHash(),
                'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
            ],
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'running'],
                'Config' => ['Labels' => [WorkspaceRuntimeContainer::SpecHashLabel => $container->specHash()]],
            ], JSON_THROW_ON_ERROR), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);

        expect($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[0])->toContain("docker container inspect --format '{{json .}}' 'orbit-ws-docs-feature-a'")
            ->and($snapshot->get('frankenphp-docs-feature-a'))->toMatchArray([
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit-ws-docs-feature-a' => [
                        'config_exists' => true,
                        'config_matches' => true,
                        'container_state' => 'running',
                    ],
                ],
            ]);
    });

    it('introspects docker containers for docker-runtime processes via docker container inspect', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'running'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => app(ProcessDockerContainerRenderer::class)->render($app, $process)->specHash()]],
            ]), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);

        expect($shell->scripts[0])->toContain('docker container inspect')
            ->and($snapshot->get('queue'))->toMatchArray([
                'runtime_backend_available' => true,
                'runtime_units' => [
                    'orbit_docs_main_queue' => [
                        'config_exists' => true,
                        'config_matches' => true,
                        'container_state' => 'running',
                    ],
                ],
            ]);
    });

    it('detects missing docker containers for docker-runtime processes', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such container', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'process.runtime_unit_missing')?->detail)->toMatchArray([
            'runtime_unit' => 'orbit_docs_main_queue',
        ]);
    });

    it('detects docker container spec hash mismatches for docker-runtime processes', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'running'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => 'wrong-hash']],
            ]), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects stale docker containers for docker-runtime processes', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'running'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => app(ProcessDockerContainerRenderer::class)->render($app, $process)->specHash()]],
            ]), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit_docs_main_oldqueue\n", stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        $extra = issue($drift, 'process.runtime_unit_extra');
        expect($extra?->kind)->toBe(DriftKind::Extra);
        expect($extra?->detail)->toMatchArray(['runtime_unit' => 'orbit_docs_main_oldqueue']);
    });

    it('does not flag created container state as drift', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'created'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => app(ProcessDockerContainerRenderer::class)->render($app, $process)->specHash()]],
            ]), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });

    it('does not flag exited container state as drift', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'exited'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => app(ProcessDockerContainerRenderer::class)->render($app, $process)->specHash()]],
            ]), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });

    it('does not flag paused container state as drift', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'paused'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => app(ProcessDockerContainerRenderer::class)->render($app, $process)->specHash()]],
            ]), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });

    it('does not flag dead container state as drift', function (): void {
        $app = processableApp(['name' => 'docs']);
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'State' => ['Status' => 'dead'],
                'Config' => ['Labels' => ['orbit.process.spec_hash' => app(ProcessDockerContainerRenderer::class)->render($app, $process)->specHash()]],
            ]), stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);
        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_unit_mismatch'))->toBeNull();
    });

    it('still detects gateway-side drift (record completeness, owner app) for docker-runtime processes', function (): void {
        $app = processableApp();
        $process = processFor($app, [
            'name' => 'queue',
            'runtime' => ProcessRuntime::Docker,
        ]);
        $process->command = '';
        $process->setRawAttributes($process->getAttributes());

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

function processableApp(array $overrides = []): App
{
    $node = createTestAppHostNode();

    return App::factory()
        ->for($node, 'node')
        ->create($overrides);
}

function processFor(App $app, array $overrides = []): Process
{
    return Process::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::None,
            // The probe pipeline below targets supervisor runtime artifacts.
            // Docker runtime probe coverage is intentionally skipped (see
            // ProcessesProbe::introspect runtime guard) and is asserted by
            // its own describe block.
            'runtime' => ProcessRuntime::Supervisor,
            'sort_order' => 1,
            ...$overrides,
        ]);
}

final class ProcessesProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
