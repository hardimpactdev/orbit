<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Schedule;
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
            'node_id' => $targetNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', 64),
        ]);

        OrbitProcess::factory()
            ->forOwner($app)
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
            ->assertJsonPath('success.data.cleanup.proxy_routes_removed', 1);

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
            'node_id' => $targetNode->id,
            'path' => '/home/orbit/apps/docs',
            'runtime' => 'static',
        ]);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', 64),
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
            ->assertJsonPath('success.data.cleanup.proxy_routes_removed', 1)
            ->assertJsonMissingPath('success.meta.warnings');

        expect(App::query()->whereKey($app->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toHaveCount(1);
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
            'node_id' => $developmentNode->id,
            'runtime' => 'static',
        ]);
        $development = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $developmentNode->id,
                node: $developmentNode->name,
                path: '/srv/docs-development',
                document_root: 'public',
                domain: 'docs-development.test',
            ),
        ]);
        $production = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
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
                'app_instance_id' => $development->id,
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        OrbitProcess::factory()
            ->forOwner($app, $productionNode)
            ->create([
                'app_instance_id' => $production->id,
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
                'node_id' => $developmentNode->id,
                'path' => '/legacy/docs',
            ]);
        AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $developmentNode->id,
                node: $developmentNode->name,
                path: '/srv/docs-development',
            ),
        ]);
        AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node: $productionNode->name,
                path: '/srv/shared-production',
            ),
        ]);

        $otherApp = App::factory()
            ->static()
            ->create([
                'name' => 'admin',
                'path' => '/legacy/admin',
            ]);
        AppInstance::factory()->for($otherApp)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
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
            'node_id' => $targetNode->id,
            'path' => '/home/orbit/apps/docs',
            'runtime' => 'php',
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
            ->assertJsonPath('success.data.cleanup.schedules_removed', 1)
            ->assertJsonPath('success.meta.warnings.0.code', 'process.runtime_unit_extra')
            ->assertJsonPath('success.meta.warnings.0.family', 'process')
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --family=process --restore')
            ->assertJsonPath('success.meta.warnings.1.code', 'app.runtime_config_extra')
            ->assertJsonPath('success.meta.warnings.1.family', 'app')
            ->assertJsonPath('success.meta.warnings.1.next_command', 'doctor --family=app --restore')
            ->assertJsonCount(2, 'success.meta.warnings');

        expect(Schedule::query()->where('app_id', $app->id)->exists())->toBeFalse();
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
            'node_id' => $targetNode->id,
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

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
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
