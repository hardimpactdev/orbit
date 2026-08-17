<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\InstanceDriver;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_REMOVE_CALLER_WG_IP = '10.6.0.80';

function createAppRemoveCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_REMOVE_CALLER_WG_IP,
        'wireguard_address' => APP_REMOVE_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRemoveAccess(Node $caller, Node $appNode, array $permissions = ['app:remove']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function appRemoveRemoteShellFallbackHeader(): array
{
    return [
        'REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP,
    ];
}

describe('AppRemoveController', function (): void {
    it('returns frozen canonical project, instance, and cleanup inventories', function (): void {
        $caller = createAppRemoveCallerNode();
        $developmentNode = Node::factory()->create([
            'name' => 'development-node',
            'status' => 'active',
        ]);
        $productionNode = Node::factory()->create([
            'name' => 'production-node',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $developmentNode);

        $app = App::factory()
            ->static()
            ->create([
                'name' => 'docs',
                'repository' => 'git@github.com:orbit/docs.git',
            ]);
        $development = Instance::factory()->for($app)->create([
            'name' => 'development',
            'adopted' => false,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $developmentNode->id,
                node: $developmentNode->name,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs-development.test',
            ),
        ]);
        $production = Instance::factory()->for($app)->create([
            'name' => 'production',
            'adopted' => true,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $productionNode->id,
                node: $productionNode->name,
                path: '/srv/docs-production',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);

        foreach ([$development, $production] as $instance) {
            $config = $instance->driver_config;
            $node = $instance->is($development) ? $developmentNode : $productionNode;

            ProxyRoute::query()->create([
                'node_id' => $node->id,
                'domain' => $config instanceof OrbitInstanceDriverConfigData ? (string) $config->domain : '',
                'app_id' => $app->id,
                'instance_id' => $instance->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('a', times: 64),
            ]);
            Schedule::factory()->forInstance($instance)->create();
            $workspace = Workspace::factory()->for($app)->create([
                'instance_id' => $instance->id,
            ]);
            OrbitProcess::factory()
                ->forOwner($app, $node)
                ->create([
                    'instance_id' => $instance->id,
                    'name' => "{$instance->name}-project",
                ]);
            OrbitProcess::factory()
                ->forOwner($workspace, $node)
                ->create([
                    'instance_id' => $instance->id,
                    'name' => "{$instance->name}-workspace",
                ]);
        }

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            ['destructive_consent' => true],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.repository', 'git@github.com:orbit/docs.git')
            ->assertJsonMissingPath('success.data.app.node')
            ->assertJsonMissingPath('success.data.app.url')
            ->assertJsonMissingPath('success.data.app.path')
            ->assertJsonMissingPath('success.data.app.root')
            ->assertJsonMissingPath('success.data.app.adopted')
            ->assertJsonPath('success.data.instances.0.app', 'docs')
            ->assertJsonPath('success.data.instances.0.name', 'development')
            ->assertJsonPath('success.data.instances.0.adopted', false)
            ->assertJsonPath('success.data.instances.1.name', 'production')
            ->assertJsonPath('success.data.instances.1.adopted', true)
            ->assertJsonPath('success.data.cleanup.aggregate.instances_removed', 2)
            ->assertJsonPath('success.data.cleanup.aggregate.proxy_routes_removed', 2)
            ->assertJsonPath('success.data.cleanup.aggregate.schedules_removed', 2)
            ->assertJsonPath('success.data.cleanup.aggregate.workspaces_removed', 2)
            ->assertJsonPath('success.data.cleanup.aggregate.processes_removed', 4)
            ->assertJsonPath('success.data.cleanup.instances.0.instance', 'docs.development')
            ->assertJsonPath('success.data.cleanup.instances.0.serving_node', 'development-node')
            ->assertJsonPath('success.data.cleanup.instances.0.proxy_routes_removed', 1)
            ->assertJsonPath('success.data.cleanup.instances.0.schedules_removed', 1)
            ->assertJsonPath('success.data.cleanup.instances.0.workspaces_removed', 1)
            ->assertJsonPath('success.data.cleanup.instances.0.processes_removed', 2)
            ->assertJsonPath('success.data.cleanup.instances.0.path_removed', true)
            ->assertJsonPath('success.data.cleanup.instances.1.instance', 'docs.production')
            ->assertJsonPath('success.data.cleanup.instances.1.path_removed', false);
    });

    it('removes app intent for authorized callers', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                node: $targetNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', times: 64),
        ]);

        OrbitProcess::factory()
            ->forOwner($app, $targetNode)
            ->create([
                'name' => 'frankenphp-docs',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-app-docs',
                    'php_ini_path' => '/home/orbit/.config/orbit/apps/docs.ini',
                ],
            ]);

        $shell = new AppRemoveApiSequencedRemoteShell([
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
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            [
                'destructive_consent' => true,
            ],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.result.action', 'removed')
            ->assertJsonPath('success.data.cleanup.aggregate.proxy_routes_removed', 1);

        expect(App::query()->where('name', 'docs')->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())
            ->toBeFalse()
            ->and(OrbitProcess::query()->where('name', 'frankenphp-docs')->exists())
            ->toBeFalse()
            ->and(collect($shell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, "docker rm -f 'orbit-app-docs'")))
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, "sudo rm -rf '/home/orbit/apps/docs'")))
            ->toBeTrue();
    });

    it('preserves malformed and stray-FK proxy routes while removing valid app ownership', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);
        $app = App::factory()->static()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                path: '/home/orbit/apps/docs',
                domain: 'docs.test',
            ),
        ]);
        $validRoute = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $malformedRoute = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'malformed.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $malformedRoute->forceFill([
            'app_id' => App::factory()->create(['name' => 'compatibility'])->id,
        ])->save();
        $strayForeignKeyRoute = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'app_id' => null,
            'instance_id' => $instance->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this
            ->call(
                'DELETE',
                '/api/apps/docs',
                ['destructive_consent' => true],
                [],
                [],
                appRemoveRemoteShellFallbackHeader(),
            )
            ->assertOk()
            ->assertJsonPath('success.data.cleanup.aggregate.proxy_routes_removed', 1);

        expect(ProxyRoute::query()->whereKey($validRoute->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->whereKey($malformedRoute->id)->exists())
            ->toBeTrue()
            ->and(ProxyRoute::query()->whereKey($strayForeignKeyRoute->id)->exists())
            ->toBeTrue();
    });

    it('removes app intent through the fixed Agent-push cleanup lane', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'static',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'production',
            'adopted' => false,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                node: $targetNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', times: 64),
        ]);

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            [
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.result.action', 'removed')
            ->assertJsonPath('success.data.cleanup.aggregate.proxy_routes_removed', 1)
            ->assertJsonMissingPath('success.meta.warnings');

        expect(App::query()->whereKey($app->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toHaveCount(1);
    });

    it('does not fall back to legacy host cleanup for a Laravel Cloud-only app', function (): void {
        $caller = createAppRemoveCallerNode();
        $legacyNode = Node::factory()->create([
            'name' => 'legacy-app-node',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $legacyNode);

        $app = App::factory()
            ->static()
            ->create([
                'name' => 'docs',
            ]);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => InstanceDriver::LaravelCloud,
            'driver_config' => new LaravelCloudInstanceDriverConfigData(
                application_id: 'app_123',
                environment_id: 'env_123',
            ),
        ]);

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            ['destructive_consent' => true],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonMissingPath('success.meta.warnings');

        expect($shell->scripts)->toBeEmpty();
    });

    it('does not fall back to legacy host cleanup for an unresolved Orbit instance placement', function (): void {
        $caller = createAppRemoveCallerNode();
        $legacyNode = Node::factory()->create([
            'name' => 'legacy-app-node',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $legacyNode);

        $app = App::factory()
            ->static()
            ->create([
                'name' => 'docs',
            ]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node: 'missing-app-node',
                path: '/srv/apps/docs',
            ),
        ]);

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            ['destructive_consent' => true],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.meta.warnings.0.code', 'instance.cleanup_failed')
            ->assertJsonPath('success.meta.warnings.0.family', 'instance')
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --family=instance --restore')
            ->assertJsonCount(1, 'success.meta.warnings');

        expect($shell->scripts)->toBeEmpty();
    });

    it('cleans resolved Orbit instances and warns for unresolved Orbit siblings without legacy fallback', function (): void {
        $caller = createAppRemoveCallerNode();
        $legacyNode = Node::factory()->create([
            'name' => 'legacy-app-node',
            'status' => 'active',
        ]);
        $resolvedNode = Node::factory()->create([
            'name' => 'resolved-app-node',
            'status' => 'active',
        ]);
        // Authorization is instance-authoritative: the caller is granted on the
        // primary instance's node, not the stale App node_id (legacy node).
        grantAppRemoveAccess($caller, $resolvedNode);

        $app = App::factory()
            ->static()
            ->create([
                'name' => 'docs',
            ]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $resolvedNode->id,
                node: $resolvedNode->name,
                path: '/srv/apps/docs-development',
            ),
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node: 'missing-app-node',
                path: '/srv/apps/docs-production',
            ),
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'staging',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node: 'missing-staging-node',
                path: '/srv/apps/docs-staging',
            ),
        ]);

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            ['destructive_consent' => true],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.meta.warnings.0.code', 'instance.cleanup_failed')
            ->assertJsonPath('success.meta.warnings.0.family', 'instance')
            ->assertJsonPath(
                'success.meta.warnings.0.message',
                'Local cleanup was skipped for Orbit instances with unresolved node placement: docs.production, docs.staging.',
            )
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --family=instance --restore')
            ->assertJsonCount(1, 'success.meta.warnings');

        expect($shell->scriptsForNode($resolvedNode))
            ->toHaveCount(1)
            ->and($shell->scriptsForNode($resolvedNode)[0])
            ->toContain("sudo rm -rf '/srv/apps/docs-development'")
            ->and($shell->scriptsForNode($legacyNode))
            ->toBeEmpty()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains($script, '/legacy/apps/docs'),
                ))
            ->toBeFalse();
    });

    it('isolates process cleanup by concrete app instance and node', function (): void {
        $caller = createAppRemoveCallerNode();
        $developmentNode = Node::factory()->create([
            'name' => 'development-node',
            'status' => 'active',
        ]);
        $productionNode = Node::factory()->create([
            'name' => 'production-node',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $developmentNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'static',
        ]);
        $development = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $developmentNode->id,
                node: $developmentNode->name,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs-development.test',
            ),
        ]);
        $production = Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $productionNode->id,
                node: $productionNode->name,
                path: '/srv/docs-production',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);

        OrbitProcess::factory()
            ->forOwner($app, $developmentNode)
            ->create([
                'instance_id' => $development->id,
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        OrbitProcess::factory()
            ->forOwner($app, $productionNode)
            ->create([
                'instance_id' => $production->id,
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this->call(
            'DELETE',
            '/api/apps/docs',
            ['destructive_consent' => true],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        )->assertOk();

        $developmentScripts = collect($shell->scriptsForNode($developmentNode));
        $productionScripts = collect($shell->scriptsForNode($productionNode));

        expect($developmentScripts->contains(
            fn (string $script): bool => str_contains($script, 'orbit_docs_development_main_queue'),
        ))
            ->toBeTrue()
            ->and($developmentScripts->contains(
                fn (string $script): bool => str_contains($script, 'orbit_docs_production_main_queue'),
            ))
            ->toBeFalse()
            ->and($productionScripts->contains(
                fn (string $script): bool => str_contains($script, 'orbit_docs_production_main_queue'),
            ))
            ->toBeTrue()
            ->and($productionScripts->contains(
                fn (string $script): bool => str_contains($script, 'orbit_docs_development_main_queue'),
            ))
            ->toBeFalse();
    });

    it('preserves an instance path shared by another app instance on the same effective node', function (): void {
        $caller = createAppRemoveCallerNode();
        $developmentNode = Node::factory()->create([
            'name' => 'development-node',
            'status' => 'active',
        ]);
        $productionNode = Node::factory()->create([
            'name' => 'production-node',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $developmentNode);

        $app = App::factory()
            ->static()
            ->create([
                'name' => 'docs',
            ]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $developmentNode->id,
                node: $developmentNode->name,
                path: '/srv/docs-development',
            ),
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node: $productionNode->name,
                path: '/srv/shared-production',
            ),
        ]);

        $otherApp = App::factory()
            ->static()
            ->create([
                'name' => 'admin',
            ]);
        Instance::factory()->for($otherApp)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $productionNode->id,
                node: $productionNode->name,
                path: '/srv/shared-production',
            ),
        ]);

        $shell = new AppRemoveApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(RunsInternalCommands::class, $shell);

        $this->call(
            'DELETE',
            '/api/apps/docs',
            ['destructive_consent' => true],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        )->assertOk();

        $developmentScripts = collect($shell->scriptsForNode($developmentNode));
        $productionScripts = collect($shell->scriptsForNode($productionNode));

        expect($developmentScripts->contains(
            fn (string $script): bool => str_contains($script, "sudo rm -rf '/srv/docs-development'"),
        ))
            ->toBeTrue()
            ->and($productionScripts)
            ->not
            ->toBeEmpty()
            ->and($productionScripts->contains(
                fn (string $script): bool => str_contains($script, "sudo rm -rf '/srv/shared-production'"),
            ))
            ->toBeFalse();
    });

    it('reports concrete runtime cleanup through process doctor while app config stays app-owned', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => 'php',
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                node: $targetNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        Schedule::factory()->forApp($app)->create();

        $shell = new AppRemoveApiSequencedRemoteShell([
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
            '/api/apps/docs',
            [
                'destructive_consent' => true,
            ],
            [],
            [],
            appRemoveRemoteShellFallbackHeader(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.cleanup.aggregate.schedules_removed', 1)
            ->assertJsonPath('success.meta.warnings.0.code', 'process.runtime_unit_extra')
            ->assertJsonPath('success.meta.warnings.0.family', 'process')
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --family=process --restore')
            ->assertJsonPath('success.meta.warnings.1.code', 'instance.runtime_config_extra')
            ->assertJsonPath('success.meta.warnings.1.family', 'instance')
            ->assertJsonPath('success.meta.warnings.1.next_command', 'doctor --family=instance --restore')
            ->assertJsonCount(2, 'success.meta.warnings');

        expect($app->schedules()->exists())->toBeFalse();
    });

    it('requires destructive consent before removing app intent', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);
        $app = App::factory()->create([
            'name' => 'docs',
        ]);

        app()->instance(RemoteShell::class, new AppRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        expect(App::query()->whereKey($app->id)->exists())->toBeTrue();
    });

    it('rejects app removal when the caller lacks app:remove on the app node', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode, ['app:read']);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                node: $targetNode->name,
            ),
        ]);

        app()->instance(RemoteShell::class, new AppRemoveApiSequencedRemoteShell([]));

        $response = $this->call(
            'DELETE',
            '/api/apps/docs',
            [
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:remove')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue();
    });
});

final class AppRemoveApiSequencedRemoteShell implements RemoteShell, RunsInternalCommands
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

    /**
     * @return list<string>
     */
    public function scriptsForNode(Node $node): array
    {
        return collect($this->scripts)
            ->filter(fn (string $script, int $index): bool => $this->nodeIds[$index] === $node->id)
            ->values()
            ->all();
    }
}
