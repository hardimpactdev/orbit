<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodePermissionPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const PROCESS_STORE_CALLER_WG_IP = '10.6.0.89';

function createProcessStoreCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_STORE_CALLER_WG_IP,
        'wireguard_address' => PROCESS_STORE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @param  list<string>  $permissions
 */
function grantProcessStoreAccess(Node $caller, Node $appNode, array $permissions = ['process:add']): void
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

function create_process_store_app_instance(App $app, Node $node, string $name = 'development'): Instance
{
    return Instance::factory()->for($app)->create([
        'name' => $name,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/'.$app->name,
            document_root: 'public',
            domain: null,
        ),
    ]);
}

describe('ProcessStoreController', function (): void {
    it('rejects app-prod callers mutating workspace processes despite a legacy add grant', function (): void {
        $caller = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-caller',
                'host' => PROCESS_STORE_CALLER_WG_IP,
                'wireguard_address' => PROCESS_STORE_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-dev-1']);
        grantProcessStoreAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = create_process_store_app_instance($app, $appNode);
        Workspace::factory()->for($app)->create([
            'instance_id' => $instance->id,
            'name' => 'feature-docs',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs.development',
                'workspace' => 'feature-docs',
                'name' => 'horizon',
                'command' => 'php artisan horizon',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-caller')
            ->assertJsonPath('error.meta.role', 'app-prod');

        expect(Process::query()->where('name', 'horizon')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('does not derive or enact workspace units during app-context writes from app-prod callers', function (): void {
        $caller = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-caller',
                'host' => PROCESS_STORE_CALLER_WG_IP,
                'wireguard_address' => PROCESS_STORE_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-dev-1']);
        grantProcessStoreAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = create_process_store_app_instance($app, $appNode);
        Workspace::factory()->for($app)->create([
            'instance_id' => $instance->id,
            'name' => 'feature-docs',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs.development',
                'name' => 'vite',
                'command' => 'npm run dev',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'success.data.runtime_units')
            ->assertJsonPath('success.data.runtime_units.0.context', 'main')
            ->assertJsonMissing(['context' => 'feature-docs']);

        expect($remoteShell->scripts)->toHaveCount(1)->and($remoteShell->scripts[0])->not->toContain('feature-docs');
    });

    it('requires an app instance when a bare app selector is ambiguous', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $developmentNode = createTestAppHostNode(['name' => 'app-development']);
        $productionNode = createTestAppHostNode(['name' => 'app-production']);
        $app = App::factory()->create(['name' => 'docs']);

        Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $developmentNode->id),
        ]);
        Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $productionNode->id),
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        expect(Process::query()->exists())->toBeFalse();
    });

    it('persists and returns the selected app instance for app process intent', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $developmentNode = createTestAppHostNode(['name' => 'app-development']);
        $productionNode = createTestAppHostNode(['name' => 'app-production']);
        $app = App::factory()->create(['name' => 'docs']);

        Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $developmentNode->id),
        ]);
        $production = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $productionNode->id),
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs.production',
                'name' => 'vite',
                'command' => 'npm run dev',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.app', 'docs')
            ->assertJsonPath('success.data.process.instance', 'production')
            ->assertJsonPath('success.data.process.workspace', null);

        $process = Process::query()->where('name', 'vite')->firstOrFail();

        expect($process->instance_id)->toBe($production->id)->and($process->node_id)->toBe($productionNode->id);
    });

    it('allows the same process name on separate app instances', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $developmentNode = createTestAppHostNode(['name' => 'app-development']);
        $productionNode = createTestAppHostNode(['name' => 'app-production']);
        $app = App::factory()->create(['name' => 'docs']);
        $development = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $developmentNode->id),
        ]);
        $production = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $productionNode->id),
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        foreach (['development', 'production'] as $instance) {
            $this->call(
                'POST',
                '/api/processes',
                [
                    'instance' => "docs.{$instance}",
                    'name' => 'vite',
                    'command' => 'npm run dev',
                    'no_start' => true,
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
            )->assertOk();
        }

        expect(Process::query()->where('name', 'vite')->count())
            ->toBe(2)
            ->and(Process::query()->where('instance_id', $development->id)->exists())
            ->toBeTrue()
            ->and(Process::query()->where('instance_id', $production->id)->exists())
            ->toBeTrue();
    });

    it('creates process intent for authorized control callers', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessStoreAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $appNode);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_development_main_vite')
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'vite')->value('runtime'))->toBe(ProcessRuntime::Systemd);
    });

    it('defaults workspace command processes to systemd for PHP app workspaces', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = create_process_store_app_instance($app, $appNode);
        $workspace = Workspace::factory()->for($app)->create([
            'instance_id' => $instance->id,
            'name' => 'feature-docs',
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'workspace' => 'feature-docs',
                'name' => 'horizon',
                'command' => 'php artisan horizon',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'horizon')
            ->assertJsonPath('success.data.process.workspace', 'feature-docs')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_development_feature-docs_horizon');

        $process = Process::query()->where('name', 'horizon')->firstOrFail();

        expect($process->owner_type)
            ->toBe($workspace->getMorphClass())
            ->and($process->owner_id)
            ->toBe($workspace->id)
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Systemd);
    });

    it('defaults macos app command processes to launchd', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = createTestAppHostNode([
            'name' => 'mac-app-dev-1',
            'platform' => 'macos_14',
            'user' => 'nckrtl',
        ]);
        grantProcessStoreAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $appNode);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.process.runtime', 'launchd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_development_main_vite');

        expect(Process::query()->where('name', 'vite')->value('runtime'))
            ->toBe(ProcessRuntime::Launchd)
            ->and(collect($remoteShell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        "internal:process-launchd-service 'apply' 'dev.hardimpact.orbit.orbit_docs_development_main_vite'",
                    ),
                ))
            ->toBeTrue();
    });

    it('rejects unauthorized callers before writing intent', function (): void {
        createProcessStoreCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $appNode);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:add');

        expect(Process::query()->exists())->toBeFalse();
    });

    it('denies app callers without a process add grant before writing intent', function (): void {
        $caller = createProcessStoreCallerNode(role: 'app-dev');
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $caller);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:add');
    });

    it('lets app-dev self grants create app-owned process intent on their own node only', function (): void {
        $caller = createProcessStoreCallerNode(role: 'app-dev');
        $otherNode = createTestAppHostNode(['name' => 'app-2']);
        $app = App::factory()->create(['name' => 'docs']);
        $hiddenApp = App::factory()->create(['name' => 'hidden']);
        create_process_store_app_instance($app, $caller);
        create_process_store_app_instance($hiddenApp, $otherNode);
        grantProcessStoreAccess(
            caller: $caller,
            appNode: $caller,
            permissions: app(NodePermissionPresets::class)->permissions('app-dev-self'),
        );
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.process.app', 'docs');

        $denied = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'hidden',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $denied
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'process:add')
            ->assertJsonPath('error.meta.serving_node', 'app-2');

        expect(Process::query()->where('name', 'vite')->exists())
            ->toBeTrue()
            ->and(Process::query()->where('name', 'queue')->exists())
            ->toBeFalse()
            ->and($remoteShell->scripts)
            ->toHaveCount(1)
            ->and($remoteShell->scripts[0])
            ->toContain("internal:process-systemd-service 'apply'");
    });

    it('returns validation errors before writing intent', function (array $payload, string $field): void {
        createProcessStoreCallerNode(role: 'gateway');
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            $payload,
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);

        expect(Process::query()->exists())->toBeFalse();
    })->with([
        'missing instance' => [['name' => 'vite', 'command' => 'npm run dev'], 'instance'],
        'missing name' => [['instance' => 'docs', 'command' => 'npm run dev'], 'name'],
        'missing command' => [['instance' => 'docs', 'name' => 'vite'], 'command'],
        'invalid restart' => [
            ['instance' => 'docs', 'name' => 'vite', 'command' => 'npm run dev', 'restart_policy' => 'sometimes'],
            'restart_policy',
        ],
    ]);

    it('persists and returns an explicit systemd runtime when supplied', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $appNode);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'legacy',
                'command' => './legacy.sh',
                'runtime' => 'systemd',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk()->assertJsonPath('success.data.process.runtime', 'systemd');

        expect(Process::query()->where('name', 'legacy')->value('runtime')->value)->toBe('systemd');
    });

    it('rejects supervisor runtime values before writing intent', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->placedOn($appNode)->create(['name' => 'docs']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'legacy',
                'command' => './legacy.sh',
                'runtime' => 'supervisor',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'supervisor')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'launchd', 'systemd']);

        expect(Process::query()->where('name', 'legacy')->exists())->toBeFalse();
    });

    it('rejects invalid runtime values with the documented validation envelope', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->placedOn($appNode)->create(['name' => 'docs']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'podman',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'podman')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'launchd', 'systemd']);

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse();
    });

    it('rejects docker swarm for app scoped process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->placedOn($appNode)->create(['name' => 'docs']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'mysql8',
                'command' => 'mysqld',
                'runtime' => 'docker-swarm',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect(Process::query()->where('name', 'mysql8')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for app scoped host-command process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->placedOn($appNode)->create(['name' => 'docs']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for workspace scoped host-command process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->placedOn($appNode)->create(['name' => 'docs']);
        Workspace::factory()
            ->for($app)
            ->for($app->instances()->firstOrFail(), 'instance')
            ->create(['name' => 'feature-docs']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'workspace' => 'feature-docs',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('creates node owned systemd process intent with an optional tool dependency', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'app-1',
                'name' => 'opencode-server',
                'command' => 'opencode serve --hostname 0.0.0.0',
                'runtime' => 'systemd',
                'tool' => 'opencode-cli',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'opencode-server')
            ->assertJsonPath('success.data.process.node', 'app-1')
            ->assertJsonPath('success.data.process.tool', 'opencode-cli')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'opencode-server');

        $process = Process::query()->where('name', 'opencode-server')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->node_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBe('opencode-cli')
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Systemd)
            ->and(collect($remoteShell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        "internal:process-systemd-service 'start' 'opencode-server.service'",
                    ),
                ))
            ->toBeTrue();
    });

    it('creates node owned Mailpit managed service processes with SMTP published and UI private', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mailpit')
            ->assertJsonPath('success.data.process.node', 'beast')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker')
            ->assertJsonPath('success.data.runtime_units.0.name', 'mailpit');

        $process = Process::query()->where('name', 'mailpit')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBeNull()
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Docker)
            ->and($process->runtime_config)
            ->toMatchArray([
                'service' => 'mailpit',
                'version_family' => 'latest',
                'version' => 'latest',
            ])
            ->and($process->runtime_config['endpoint'])
            ->toMatchArray([
                'name' => 'smtp',
                'host' => '10.6.0.7',
                'port' => 1025,
            ])
            ->and($process->runtime_config['endpoints'])
            ->toBe([
                [
                    'name' => 'smtp',
                    'kind' => 'tcp',
                    'host' => '10.6.0.7',
                    'port' => 1025,
                ],
            ])
            ->and($process->runtime_config['labels']['orbit.process.service'])
            ->toBe('mailpit')
            ->and($process->command)
            ->toBe('/mailpit');

        $create = collect($remoteShell->scripts)
            ->first(fn (string $script): bool => str_contains($script, 'internal:process-docker-container'));

        expect($create)->toBeString()->toContain('internal:process-docker-container')->toContain('--json');
    });

    it('removes explicit replacement containers before creating a Docker managed service process', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Name":"dngdmt-mailpit-1"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '{"Name":"orbit-mailpit"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
                'replace_containers' => ['dngdmt-mailpit-1', 'orbit-mailpit'],
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mailpit')
            ->assertJsonPath('success.data.replaced_containers', ['dngdmt-mailpit-1', 'orbit-mailpit']);

        expect(collect($remoteShell->scripts)
            ->contains(
                fn (string $script): bool => str_contains($script, 'internal:process-docker-container'),
            ))->toBeTrue();
    });

    it('requires destructive consent before replacing containers', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
                'replace_containers' => ['dngdmt-mailpit-1'],
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.meta.reason', 'destructive_consent_required');

        expect(Process::query()->where('name', 'mailpit')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('does not write process configuration when replacement container removal fails', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Name":"dngdmt-mailpit-1"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
                'replace_containers' => ['dngdmt-mailpit-1'],
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'process.replace_container_failed')
            ->assertJsonPath('error.meta.container', 'dngdmt-mailpit-1');

        expect(Process::query()->where('name', 'mailpit')->exists())
            ->toBeFalse()
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, 'docker create')))
            ->toBeFalse();
    });

    it('rejects replacement containers outside node owned Docker managed services', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker-swarm',
                'replace_containers' => ['dngdmt-mailpit-1'],
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'replace_containers')
            ->assertJsonPath('error.meta.reason', 'replace_container_requires_node_docker_service');

        expect(Process::query()->where('name', 'mailpit')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('creates node owned MySQL managed service processes without tool rows', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'database-1',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8',
                'runtime' => 'docker-swarm',
                'restart_policy' => 'on_failure',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mysql8')
            ->assertJsonPath('success.data.process.node', 'database-1')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker-swarm')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit-mysql8');

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBeNull()
            ->and($process->runtime)
            ->toBe(ProcessRuntime::DockerSwarm)
            ->and($process->runtime_config)
            ->toMatchArray([
                'service' => 'mysql',
                'version_family' => '8',
                'version' => '8.4',
            ])
            ->and($process->runtime_config['endpoint']['host'])
            ->toBe('10.6.0.44')
            ->and($process->runtime_config['endpoint']['port'])
            ->toBe(3308)
            ->and($process->runtime_config['labels']['orbit.process.service'])
            ->toBe('mysql')
            ->and($process->runtime_config['labels']['orbit.process.version_family'])
            ->toBe('8')
            ->and($remoteShell->scripts[0])
            ->toContain("internal:process-docker-swarm-service 'apply' 'orbit-mysql8'")
            ->and($remoteShell->scripts[0])
            ->toContain('--json');
    });

    it('stores generated PostgreSQL credentials encrypted and binds the service to WireGuard', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $this
            ->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database-1',
                    'name' => 'postgres',
                    'service' => 'postgres',
                    'version' => '16',
                    'runtime' => 'docker',
                    'service_options' => [
                        'database' => 'plausible_db',
                        'username' => 'orbit',
                        'published_port' => 5432,
                    ],
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
            )
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'postgres')
            ->assertJsonPath('success.data.process.runtime', 'docker');

        $process = Process::query()->where('name', 'postgres')->firstOrFail();
        $credentials = $process->credentials;
        $password = is_array($credentials) ? $credentials['password'] ?? null : null;
        $rawCredentials = (string) $process->getRawOriginal('credentials');
        $runtimeConfigJson = json_encode($process->runtime_config, JSON_THROW_ON_ERROR);

        expect($process->owner_id)
            ->toBe($node->id)
            ->and($process->runtime_config['ports'][0])
            ->toMatchArray([
                'host' => '10.6.0.44',
                'published' => 5432,
                'target' => 5432,
            ])
            ->and($process->runtime_config['command_mode'])
            ->toBe('image_entrypoint')
            ->and($credentials)
            ->toMatchArray([
                'database' => 'plausible_db',
                'username' => 'orbit',
            ])
            ->and($password)
            ->toBeString()
            ->not->toBe('')->and($credentials['environment']['POSTGRES_PASSWORD'])->toBe($password)->and(
                $rawCredentials,
            )
            ->not->toContain($password)->and($runtimeConfigJson)
            ->not->toContain($password)->and(implode("\n", $remoteShell->scripts))
            ->not->toContain($password);
    });

    it('lets PostgreSQL 16 and 18 coexist with independent process resources on one node', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database1',
            'wireguard_address' => '10.6.0.4',
        ]);
        app()->instance(
            RemoteShell::class,
            new ProcessStoreRemoteShell(array_fill(
                0,
                6,
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            )),
        );

        foreach ([
            ['postgres',      '16', 'plausible_db',        'orbit',               5432],
            ['postgres-food', '18', 'mealou_food_catalog', 'mealou_food_catalog', 5433],
        ] as [$name, $version, $database, $username, $publishedPort]) {
            $this->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database1',
                    'name' => $name,
                    'service' => 'postgres',
                    'version' => $version,
                    'restart_policy' => 'always',
                    'service_options' => [
                        'database' => $database,
                        'username' => $username,
                        'published_port' => $publishedPort,
                    ],
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
            )->assertOk();
        }

        $this
            ->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database1',
                    'name' => 'postgres-conflict',
                    'service' => 'postgres',
                    'version' => '18',
                    'service_options' => [
                        'database' => 'conflict',
                        'username' => 'conflict',
                        'published_port' => 5432,
                    ],
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
            )
            ->assertStatus(422)
            ->assertJsonPath('error.meta.reason', 'endpoint_conflict')
            ->assertJsonPath('error.meta.host', '10.6.0.4')
            ->assertJsonPath('error.meta.port', 5432);

        $postgres16 = Process::query()->where('name', 'postgres')->firstOrFail();
        $postgres18 = Process::query()->where('name', 'postgres-food')->firstOrFail();

        expect($postgres16->owner_id)
            ->toBe($node->id)
            ->and($postgres18->owner_id)
            ->toBe($node->id)
            ->and($postgres16->runtime_config['service'])
            ->toBe('postgres')
            ->and($postgres18->runtime_config['service'])
            ->toBe('postgres')
            ->and($postgres16->runtime_config['version_family'])
            ->toBe('16')
            ->and($postgres18->runtime_config['version_family'])
            ->toBe('18')
            ->and($postgres16->runtime_config['version'])
            ->toBe('16-alpine')
            ->and($postgres18->runtime_config['version'])
            ->toBe('18-alpine')
            ->and($postgres16->runtime_config['endpoint']['port'])
            ->toBe(5432)
            ->and($postgres18->runtime_config['endpoint']['port'])
            ->toBe(5433)
            ->and($postgres16->runtime_config['service_name'])
            ->toBe('orbit-postgres')
            ->and($postgres18->runtime_config['service_name'])
            ->toBe('orbit-postgres-food')
            ->and($postgres16->runtime_config['mounts'][0]['source'])
            ->not->toBe($postgres18->runtime_config['mounts'][0]['source'])->and(
                $postgres16->runtime_config['volumes'][0]['name'],
            )
            ->not->toBe($postgres18->runtime_config['volumes'][0]['name'])->and($postgres16->credentials['password'])
            ->not->toBe($postgres18->credentials['password'])->and(
                Process::query()->where('name', 'postgres-conflict')->exists(),
            )->toBeFalse();
    });

    it('lets MySQL 8 and MySQL 9 managed services coexist on one node', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        foreach ([['mysql8', '8'], ['mysql9', '9']] as [$name, $version]) {
            $this->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database-1',
                    'name' => $name,
                    'service' => 'mysql',
                    'version' => $version,
                    'runtime' => 'docker',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
            )->assertOk();
        }

        $mysql8 = Process::query()->where('name', 'mysql8')->firstOrFail();
        $mysql9 = Process::query()->where('name', 'mysql9')->firstOrFail();

        expect($mysql8->owner_id)
            ->toBe($node->id)
            ->and($mysql9->owner_id)
            ->toBe($node->id)
            ->and($mysql8->runtime_config['endpoint']['port'])
            ->toBe(3308)
            ->and($mysql9->runtime_config['endpoint']['port'])
            ->toBe(3309)
            ->and($mysql8->runtime_config['spec_hash'])
            ->not->toBe($mysql9->runtime_config['spec_hash']);
    });

    it('rejects invalid managed service input before runtime side effects', function (
        array $payload,
        string $field,
        string $reason,
    ): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'database-1']);
        App::factory()->placedOn($node)->create(['name' => 'docs']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            $payload,
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.reason', $reason);

        expect(Process::query()->whereIn('name', ['redis', 'valkey', 'mysql8'])->exists())
            ->toBeFalse()
            ->and($remoteShell->scripts)
            ->toBe([]);
    })->with([
        'retired Redis service' => [
            [
                'node' => 'database-1',
                'name' => 'redis',
                'service' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
            ],
            'service',
            'unsupported_value',
        ],
        'app owner' => [
            [
                'instance' => 'docs',
                'name' => 'valkey',
                'service' => 'valkey',
                'version' => '8',
                'runtime' => 'docker',
            ],
            'service',
            'process_service_requires_node_owned_process',
        ],
        'tool dependency' => [
            [
                'node' => 'database-1',
                'name' => 'valkey',
                'service' => 'valkey',
                'version' => '8',
                'runtime' => 'docker',
                'tool' => 'valkey',
            ],
            'tool',
            'process_service_cannot_reference_tool',
        ],
        'version without service' => [
            [
                'node' => 'database-1',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'version' => '8',
                'runtime' => 'docker',
            ],
            'version',
            'process_service_version_requires_service',
        ],
        'image without service' => [
            [
                'node' => 'database-1',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'image' => 'docker.io/library/mysql:8.3',
                'runtime' => 'docker',
            ],
            'image',
            'process_service_image_requires_service',
        ],
        'image with systemd runtime' => [
            [
                'node' => 'database-1',
                'name' => 'node-exporter',
                'service' => 'node-exporter',
                'runtime' => 'systemd',
                'image' => 'prom/node-exporter:v1.11.1',
            ],
            'image',
            'process_service_image_requires_docker_runtime',
        ],
        'missing PostgreSQL database' => [
            [
                'node' => 'database-1',
                'name' => 'postgres-food',
                'service' => 'postgres',
                'version' => '18',
                'service_options' => [
                    'username' => 'mealou_food_catalog',
                    'published_port' => 5433,
                ],
            ],
            'service_options.database',
            'required',
        ],
        'invalid PostgreSQL username' => [
            [
                'node' => 'database-1',
                'name' => 'postgres-food',
                'service' => 'postgres',
                'version' => '18',
                'service_options' => [
                    'database' => 'mealou_food_catalog',
                    'username' => 'Mealou User',
                    'published_port' => 5433,
                ],
            ],
            'service_options.username',
            'invalid_postgres_identifier',
        ],
        'invalid PostgreSQL published port' => [
            [
                'node' => 'database-1',
                'name' => 'postgres-food',
                'service' => 'postgres',
                'version' => '18',
                'service_options' => [
                    'database' => 'mealou_food_catalog',
                    'username' => 'mealou_food_catalog',
                    'published_port' => 65536,
                ],
            ],
            'service_options.published_port',
            'out_of_range',
        ],
        'PostgreSQL options for another service' => [
            [
                'node' => 'database-1',
                'name' => 'valkey',
                'service' => 'valkey',
                'version' => '8',
                'service_options' => [
                    'database' => 'ignored',
                    'username' => 'ignored',
                    'published_port' => 5433,
                ],
            ],
            'service_options',
            'process_service_options_unsupported',
        ],
        'PostgreSQL image major mismatch' => [
            [
                'node' => 'database-1',
                'name' => 'postgres-food',
                'service' => 'postgres',
                'version' => '16',
                'image' => 'postgres:18-alpine',
                'service_options' => [
                    'database' => 'mealou_food_catalog',
                    'username' => 'mealou_food_catalog',
                    'published_port' => 5433,
                ],
            ],
            'image',
            'process_service_image_version_mismatch',
        ],
    ]);

    it('rejects managed service endpoint conflicts before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'existing-valkey',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'endpoints' => [
                        ['name' => 'existing-valkey', 'kind' => 'tcp', 'host' => '10.6.0.44', 'port' => 6379],
                    ],
                ],
            ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'database-1',
                'name' => 'valkey',
                'service' => 'valkey',
                'version' => '8',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'endpoint_conflict')
            ->assertJsonPath('error.meta.existing_process', 'existing-valkey')
            ->assertJsonPath('error.meta.port', 6379);

        expect(Process::query()->where('name', 'valkey')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects managed service conflicts with existing published ports before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'seaweedfs',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'ports' => [
                        ['host' => '10.6.0.44', 'published' => 5432, 'target' => 8333, 'protocol' => 'tcp'],
                    ],
                ],
            ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'database-1',
                'name' => 'postgres',
                'service' => 'postgres',
                'version' => '16',
                'service_options' => [
                    'database' => 'plausible_db',
                    'username' => 'orbit',
                    'published_port' => 5432,
                ],
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'endpoint_conflict')
            ->assertJsonPath('error.meta.existing_process', 'seaweedfs')
            ->assertJsonPath('error.meta.port', 5432);

        expect(Process::query()->where('name', 'postgres')->exists())
            ->toBeFalse()
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('returns duplicate process conflicts', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->placedOn($appNode)->create(['name' => 'docs']);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertStatus(409)->assertJsonPath('error.code', 'process.name_collision');
    });

    it('creates node owned MySQL processes from the service selector with default start', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8.3',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mysql8')
            ->assertJsonPath('success.data.process.node', 'beast')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker')
            ->assertJsonPath('success.data.runtime_units.0.name', 'mysql8');

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBeNull()
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Docker)
            ->and($process->runtime_config['service'])
            ->toBe('mysql')
            ->and($process->runtime_config['version'])
            ->toBe('8.3')
            ->and($process->runtime_config['image'])
            ->toBe('mysql:8.3')
            ->and($process->runtime_config['command_mode'])
            ->toBe('image_entrypoint')
            ->and($process->runtime_config['ports'][0])
            ->toBe([
                'host' => '10.6.0.44',
                'published' => 3308,
                'target' => 3306,
                'protocol' => 'tcp',
            ])
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, 'internal:process-docker-container')))
            ->toBeTrue()
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, '--json')))
            ->toBeTrue();
    });

    it('accepts an explicit image override for docker managed service processes', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8.3',
                'runtime' => 'docker',
                'image' => 'docker.io/library/mysql:8.3',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk();

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->runtime_config['image'])->toBe('docker.io/library/mysql:8.3');
    });

    it('does not start managed service runtime units when no_start is true', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8.3',
                'runtime' => 'docker',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk();

        expect(collect($remoteShell->scripts)
            ->contains(fn (string $script): bool => str_contains($script, "docker start 'mysql8'")))->toBeFalse();
    });

    it('does not infer managed service from process name alone', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode(['name' => 'beast']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'command');

        expect(Process::query()->where('name', 'mysql8')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects service versions without service before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode(['name' => 'beast']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'version' => '8.3',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'version')
            ->assertJsonPath('error.meta.reason', 'process_service_version_requires_service');

        expect(Process::query()->where('name', 'worker')->exists())
            ->toBeFalse()
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('accepts launchd runtime for host command processes on macos nodes', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = createTestAppHostNode(['platform' => 'darwin']);
        grantProcessStoreAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $appNode);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'feedback-worker',
                'command' => 'php artisan queue:work',
                'runtime' => 'launchd',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk()->assertJsonPath('success.data.process.runtime', 'launchd');

        expect(Process::query()->where('name', 'feedback-worker')->value('runtime'))->toBe(ProcessRuntime::Launchd);
    });

    it('rejects launchd runtime on linux nodes with launchd_runtime_requires_macos', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = createTestAppHostNode(['platform' => 'ubuntu_24-04']);
        grantProcessStoreAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs']);
        create_process_store_app_instance($app, $appNode);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'instance' => 'docs',
                'name' => 'feedback-worker',
                'command' => 'php artisan queue:work',
                'runtime' => 'launchd',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertStatus(422);
        $json = $response->json();
        expect($json['error']['code'] ?? null)->toBe('validation_failed');
        expect($json['error']['meta']['reason'] ?? null)->toBe('launchd_runtime_requires_macos');
    });
});

final class ProcessStoreRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, 'internal:process-systemd-service')) {
            return $this->internalSuccessResult([
                'status' => 'ok',
                'summary' => 'Applied systemd service.',
            ]);
        }

        if (str_contains($script, 'internal:process-launchd-service')) {
            return $this->internalSuccessResult([
                'status' => 'ok',
                'summary' => 'Applied launchd service.',
            ]);
        }

        if (str_contains($script, 'internal:process-docker-container')) {
            return $this->internalDockerResult();
        }

        if (str_contains($script, 'internal:process-docker-swarm-service')) {
            return $this->internalSuccessResult([
                'status' => 'ok',
            ]);
        }

        if (str_contains($script, 'sudo systemctl is-enabled "$service"')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'exists' => false,
                    'hash' => null,
                    'enabled' => false,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    private function internalDockerResult(): RemoteShellResult
    {
        foreach ($this->results as $index => $result) {
            if ($result->exitCode === 0) {
                continue;
            }

            array_splice($this->results, 0, $index + 1);

            return $result;
        }

        if ($this->results !== []) {
            array_shift($this->results);
        }

        return $this->internalSuccessResult([
            'outcome' => 'created',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function internalSuccessResult(array $data): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        );
    }
}
