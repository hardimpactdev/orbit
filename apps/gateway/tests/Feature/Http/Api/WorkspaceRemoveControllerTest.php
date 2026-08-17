<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\InstanceDriver;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_REMOVE_CALLER_WG_IP = '10.6.0.81';

function createWorkspaceRemoveCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_REMOVE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_REMOVE_CALLER_WG_IP,
    ], $overrides);

    if ($role === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

function grantWorkspaceRemoveAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:remove'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function workspaceRemoveRemoteShellFallbackHeader(): array
{
    return [
        'REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP,
    ];
}

describe('WorkspaceRemoveController', function (): void {
    it('does not execute a legacy teardown step that consumes the parent env', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'static',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs-development.test',
            ),
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'command' => 'cp "$ORBIT_APP_PATH/.env" .env.backup',
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'feature-api',
            'path' => '/srv/docs-development-feature-api',
        ]);
        $shell = new WorkspaceRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?instance=docs.development',
            [
                'keep_files' => true,
                'destructive_consent' => true,
            ],
            [],
            [],
            workspaceRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.teardown_steps_run', 0)
            ->assertJsonPath('success.meta.warnings.0.code', 'workspace.teardown_step_unsafe');

        expect(collect($shell->scripts)
            ->contains(
                static fn (string $script): bool => str_contains($script, 'ORBIT_APP_PATH'),
            ))->toBeFalse();
    });

    it('removes workspace intent for authorized callers', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);

        $app = App::factory()
            ->placedOn($targetNode)
            ->create([
                'name' => 'docs',
            ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'name' => 'feature-api',
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'feature-api.docs.test',
            'app_id' => $app->id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        OrbitProcess::factory()
            ->forOwner($workspace)
            ->create([
                'name' => 'frankenphp-docs-feature-api',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-ws-docs-feature-api',
                    'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
                    'php_ini_path' => '/home/orbit/.config/orbit/workspaces/docs-feature-api.ini',
                ],
            ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Id":"abc"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: 'orbit-container-config-probe:present',
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: 'orbit-container-config-probe:absent',
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?instance=docs',
            [
                'keep_files' => false,
                'destructive_consent' => true,
            ],
            [],
            [],
            workspaceRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.name', 'feature-api')
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.proxy_routes_removed', 1)
            ->assertJsonPath('success.meta.kept_files', false);

        expect(Workspace::query()->whereKey($workspace->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'feature-api.docs.test')->exists())
            ->toBeFalse()
            ->and(OrbitProcess::query()->where('name', 'frankenphp-docs-feature-api')->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->not
            ->toBe([])
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        "docker rm -f 'orbit-ws-docs-feature-api' 2>/dev/null || true",
                    ),
                ))
            ->toBeFalse();
    });

    it('preserves malformed and stray-FK proxy routes while removing valid workspace ownership', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);
        $app = App::factory()->static()->placedOn($targetNode)->create(['name' => 'docs']);
        $instance = $app->instances()->firstOrFail();
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'feature-api',
        ]);
        $validRoute = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'feature-api.docs.test',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        $malformedRoute = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'malformed.docs.test',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        $malformedRoute->forceFill([
            'app_id' => App::factory()->create(['name' => 'compatibility'])->id,
        ])->save();
        $strayForeignKeyRoute = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'custom.docs.test',
            'app_id' => null,
            'instance_id' => null,
            'workspace_id' => $workspace->id,
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        $shell = new WorkspaceRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this
            ->call(
                'DELETE',
                '/api/workspaces/feature-api?instance=docs',
                [
                    'keep_files' => true,
                    'destructive_consent' => true,
                ],
                [],
                [],
                workspaceRemoveRemoteShellFallbackHeader(),
            )
            ->assertOk()
            ->assertJsonPath('success.data.proxy_routes_removed', 1);

        expect(ProxyRoute::query()->whereKey($validRoute->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->whereKey($malformedRoute->id)->exists())
            ->toBeTrue()
            ->and(ProxyRoute::query()->whereKey($strayForeignKeyRoute->id)->exists())
            ->toBeTrue();
    });

    it('removes an app-instance workspace on the selected instance node', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceRemoveAccess($caller, $localNode);

        $app = App::factory()->create([
            'name' => 'happie',
        ]);
        $instance = Instance::factory()
            ->for($app)
            ->create([
                'name' => 'nmbp',
                'driver' => InstanceDriver::Orbit,
                'driver_config' => new OrbitInstanceDriverConfigData(
                    node_id: $localNode->id,
                    node: 'NMBP',
                    path: '/Users/nckrtl/apps/happie',
                    document_root: 'public',
                    domain: 'happie.nmbp',
                ),
            ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'recipes',
            'path' => '/Users/nckrtl/.codex/worktrees/a59f/happie',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $localNode->id,
            'domain' => 'recipes.happie.nmbp',
            'app_id' => $app->id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Id":"abc"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: 'orbit-container-config-probe:absent',
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/recipes?instance=happie.nmbp',
            [
                'keep_files' => true,
                'destructive_consent' => true,
            ],
            [],
            [],
            workspaceRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.name', 'recipes')
            ->assertJsonPath('success.data.app', 'happie')
            ->assertJsonPath('success.data.instance', 'nmbp')
            ->assertJsonPath('success.data.proxy_routes_removed', 1)
            ->assertJsonPath('success.meta.kept_files', true);

        expect(Workspace::query()->whereKey($workspace->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'recipes.happie.nmbp')->exists())
            ->toBeFalse()
            ->and($shell->nodeIds)
            ->not
            ->toBe([])
            ->and(array_unique($shell->nodeIds))
            ->toBe([$localNode->id]);
    });

    it('cleans up only inherited processes from the workspace app instance', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $sharedNode = createTestAppHostNode([
            'name' => 'shared-app-host',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $sharedNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'static',
        ]);
        $development = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $sharedNode->id,
                node: $sharedNode->name,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs-development.test',
            ),
        ]);
        $production = Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $sharedNode->id,
                node: $sharedNode->name,
                path: '/srv/docs-production',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $development->id,
            'name' => 'feature-api',
            'path' => '/srv/docs-development-feature-api',
        ]);

        OrbitProcess::factory()
            ->forOwner($app, $sharedNode)
            ->create([
                'instance_id' => $development->id,
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        OrbitProcess::factory()
            ->forOwner($app, $sharedNode)
            ->create([
                'instance_id' => $production->id,
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this
            ->call(
                'DELETE',
                '/api/workspaces/feature-api?instance=docs.development',
                [
                    'keep_files' => true,
                    'destructive_consent' => true,
                ],
                [],
                [],
                workspaceRemoveRemoteShellFallbackHeader(),
            )
            ->assertOk()
            ->assertJsonPath('success.data.processes_removed', 1);

        expect(collect($shell->scripts)
            ->contains(
                fn (string $script): bool => str_contains(
                    $script,
                    'orbit_docs_development_feature-api_queue',
                ),
            ))
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        'orbit_docs_production_feature-api_queue',
                    ),
                ))
            ->toBeFalse();
    });

    it('preserves single-context main app containers while cleaning inherited workspace units', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $appNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $appNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'php',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'feature-api',
            'path' => '/srv/docs-development-feature-api',
        ]);

        OrbitProcess::factory()
            ->forOwner($app, $appNode)
            ->create([
                'name' => 'frankenphp-docs',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-app-docs-development',
                    'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                ],
            ]);
        OrbitProcess::factory()
            ->forOwner($app, $appNode)
            ->create([
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'No such container',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: 'orbit-container-config-probe:absent',
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this
            ->call(
                'DELETE',
                '/api/workspaces/feature-api?instance=docs.development',
                [
                    'keep_files' => true,
                    'destructive_consent' => true,
                ],
                [],
                [],
                workspaceRemoveRemoteShellFallbackHeader(),
            )
            ->assertOk()
            ->assertJsonPath('success.data.processes_removed', 1);

        expect(Workspace::query()->whereKey($workspace->id)->exists())
            ->toBeFalse()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        "docker rm -f 'orbit-app-docs-development'",
                    ),
                ))
            ->toBeFalse()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        'orbit_docs_development_feature-api_queue.service',
                    ),
                ))
            ->toBeTrue();
    });

    it('removes workspace-owned systemd units before deleting their process definitions', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $appNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $appNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'static',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs-development.test',
            ),
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => 'feature-api',
            'path' => '/srv/docs-development-feature-api',
        ]);
        $process = OrbitProcess::factory()
            ->forOwner($workspace, $appNode)
            ->create([
                'name' => 'worker',
                'runtime' => ProcessRuntime::Systemd,
            ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this
            ->call(
                'DELETE',
                '/api/workspaces/feature-api?instance=docs.development',
                [
                    'keep_files' => true,
                    'destructive_consent' => true,
                ],
                [],
                [],
                workspaceRemoveRemoteShellFallbackHeader(),
            )
            ->assertOk()
            ->assertJsonPath('success.data.processes_removed', 1);

        expect(OrbitProcess::query()->whereKey($process->id)->exists())
            ->toBeFalse()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        'orbit_docs_development_feature-api_worker.service',
                    ),
                ))
            ->toBeTrue();
    });

    it('removes workspace intent through the fixed Agent-push cleanup lane', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);

        $app = App::factory()
            ->placedOn($targetNode)
            ->create([
                'name' => 'docs',
                'runtime' => 'static',
            ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'name' => 'feature-api',
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'feature-api.docs.test',
            'app_id' => $app->id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?instance=docs',
            [
                'keep_files' => false,
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.name', 'feature-api')
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.proxy_routes_removed', 1)
            ->assertJsonPath('success.data.worktree_removed', true)
            ->assertJsonMissingPath('success.meta.warnings');

        expect(Workspace::query()->whereKey($workspace->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'feature-api.docs.test')->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toHaveCount(2);
    });

    it('reports concrete runtime cleanup through process doctor while workspace config stays workspace-owned', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);

        $app = App::factory()
            ->placedOn($targetNode)
            ->create([
                'name' => 'docs',
                'runtime' => 'php',
            ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'name' => 'feature-api',
        ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Cannot connect to the Docker daemon',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-config-probe:error\n",
                stderr: 'permission denied',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?instance=docs',
            [
                'keep_files' => false,
                'destructive_consent' => true,
            ],
            [],
            [],
            workspaceRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.meta.warnings.0.code', 'process.runtime_unit_extra')
            ->assertJsonPath('success.meta.warnings.0.family', 'process')
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --family=process --restore')
            ->assertJsonPath('success.meta.warnings.1.code', 'workspace.runtime_config_extra')
            ->assertJsonPath('success.meta.warnings.1.family', 'workspace')
            ->assertJsonPath('success.meta.warnings.1.next_command', 'doctor --family=workspace --restore')
            ->assertJsonCount(2, 'success.meta.warnings');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse();
    });

    it('requires destructive consent before removing workspace intent', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);
        $app = App::factory()
            ->placedOn($targetNode)
            ->create([
                'name' => 'docs',
            ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'name' => 'feature-api',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([]));

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });

    it('rejects workspace removal when the caller cannot access the app node', function (): void {
        createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);

        $app = App::factory()
            ->placedOn($targetNode)
            ->create([
                'name' => 'docs',
            ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'name' => 'feature-api',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([]));

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?instance=docs',
            [
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:remove');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });
});

final class WorkspaceRemoveApiSequencedRemoteShell implements RemoteShell, RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<int>
     */
    public array $nodeIds = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodeIds[] = $node->id;
        $this->scripts[] = $script;

        return (
            array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            )
        );
    }

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $payload = json_decode(
            (string) ($transportOptions['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $result = $this->run($node, (string) ($payload['script'] ?? ''), $transportOptions);

        if (! $result->successful()) {
            return $result;
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => [
                    'exit_code' => $result->exitCode,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                    'duration_ms' => $result->durationMs,
                ]],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }
}
