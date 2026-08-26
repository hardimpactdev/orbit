<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppDevelopmentSetupStep;
use App\Models\AppSetupStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Runtime\DockerCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
    app()->instance(OrbitCaService::class, new AppRegisterControllerTestCa);
    app()->bind(
        AppRuntimeContainerManager::class,
        fn (): AppRuntimeContainerManager => new AppRuntimeContainerManager(
            commands: app(DockerCommandBuilder::class),
            ca: app(OrbitCaService::class),
        ),
    );
});

const APP_REGISTER_CALLER_WG_IP = '10.6.0.78';

final readonly class AppRegisterControllerTestCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }
}

function createAppRegisterCallerNode(array $overrides = [], ?string $role = null): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_REGISTER_CALLER_WG_IP,
        'wireguard_address' => APP_REGISTER_CALLER_WG_IP,
    ], $overrides));

    if ($role !== null) {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRegisterAccess(Node $caller, Node $appNode, array $permissions = ['instance:register']): void
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

/**
 * @return array<string, string>
 */
function app_register_fallback_server(): array
{
    return [
        'REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP,
    ];
}

describe('AppRegisterController', function (): void {
    it('copies app defaults once when registration creates an app-dev instance', function (): void {
        createTestGatewayNode(['name' => 'gateway-1']);
        $caller = createAppRegisterCallerNode();
        $existingNode = createTestAppHostNode([
            'name' => 'nmbp',
            'tld' => 'nmbp',
            'status' => 'active',
            'wireguard_address' => '10.6.0.40',
            'managed' => true,
        ]);
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.41',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.41', '/srv/docs');

        $app = App::factory()->create(['name' => 'docs']);
        app_register_instance(
            app: $app,
            name: 'nmbp',
            node: $existingNode,
            path: '/srv/existing',
        );
        $second = AppDevelopmentSetupStep::factory()->for($app)->create([
            'sort_order' => 2,
            'command' => 'second',
            'timeout_seconds' => 42,
        ]);
        AppDevelopmentSetupStep::factory()->for($app)->create([
            'sort_order' => 1,
            'command' => 'first',
            'timeout_seconds' => 21,
        ]);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $register = fn () => $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs.development',
                'node' => 'app-1',
                'path' => '/srv/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $register()->assertOk();
        $instance = Instance::query()->whereBelongsTo($app)->where('name', 'development')->sole();
        $copied = AppSetupStep::query()->whereBelongsTo($instance)->orderBy('sort_order')->get();

        expect($copied->pluck('command')->all())
            ->toBe(['first', 'second'])
            ->and($copied->pluck('timeout_seconds')->all())
            ->toBe([21, 42]);

        $second->update(['command' => 'changed']);
        expect($copied->fresh()->pluck('command')->all())->toBe(['first', 'second']);

        $register()->assertOk();
        expect(AppSetupStep::query()->whereBelongsTo($instance)->count())->toBe(2);
    });

    it('does not copy development defaults for app-prod registration', function (): void {
        $gateway = createTestGatewayNode([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.1',
        ]);
        NodeRoleAssignment::factory()->for($gateway)->create([
            'role' => 'router',
            'status' => 'active',
        ]);
        $ingressNode = Node::factory()
            ->ingress()
            ->managed()
            ->create([
                'name' => 'ingress-1',
                'wireguard_address' => '10.6.0.2',
            ]);
        $caller = createAppRegisterCallerNode();
        $existingNode = createTestAppHostNode([
            'name' => 'nmbp',
            'tld' => 'nmbp',
            'status' => 'active',
            'wireguard_address' => '10.6.0.40',
            'managed' => true,
        ]);
        $targetNode = createTestAppHostNode(
            attributes: [
                'name' => 'prod-1',
                'tld' => 'example',
                'status' => 'active',
                'wireguard_address' => '10.6.0.42',
                'managed' => true,
            ],
            role: 'app-prod',
        );
        $targetNode
            ->roleAssignments()
            ->where('role', 'app-prod')
            ->update(['settings' => ['ingress_node_id' => $ingressNode->id]]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.42', '/srv/docs');

        $app = App::factory()->create(['name' => 'docs']);
        app_register_instance(
            app: $app,
            name: 'nmbp',
            node: $existingNode,
            path: '/srv/existing',
        );
        AppDevelopmentSetupStep::factory()->for($app)->create(['command' => 'development only']);
        app()->instance(RemoteShell::class, new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs.production',
                'node' => 'prod-1',
                'path' => '/srv/docs',
                'domain' => 'docs.example.com',
            ],
            [],
            [],
            app_register_fallback_server(),
        )->assertOk();

        $instance = Instance::query()->whereBelongsTo($app)->where('name', 'production')->sole();
        expect(AppSetupStep::query()->whereBelongsTo($instance)->count())->toBe(0);
    });

    it('registers an existing app path for authorized callers', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.41',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.41');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.instance.node', 'app-1')
            ->assertJsonMissingPath('success.data.app.node')
            ->assertJsonPath('success.data.app.runtime', 'php')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'http')
            ->assertJsonMissingPath('success.data.app.worker_enabled')
            ->assertJsonMissingPath('success.data.app.worker_config')
            ->assertJsonPath('success.meta.node', 'app-1')
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())
            ->toBeTrue();

        expect($remoteShell->scripts)
            ->toContainAppRegisterSourcePathProbe('/home/orbit/apps/docs');
    });

    it('lets app-dev self grants register apps on their own node only', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createTestAppHostNode([
            'name' => 'dev-1',
            'host' => APP_REGISTER_CALLER_WG_IP,
            'wireguard_address' => APP_REGISTER_CALLER_WG_IP,
            'managed' => true,
        ]);
        $otherNode = createTestAppHostNode([
            'name' => 'dev-2',
            'wireguard_address' => '10.6.0.46',
            'managed' => true,
        ]);
        grantAppRegisterAccess(
            caller: $caller,
            appNode: $caller,
            permissions: app(NodePermissionPresets::class)->permissions('app-dev-self'),
        );
        fake_app_register_source_path_probe(APP_REGISTER_CALLER_WG_IP);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'dev-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.instance.node', 'dev-1');

        $denied = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'hidden',
                'node' => $otherNode->name,
                'path' => '/home/orbit/apps/hidden',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $denied
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:register')
            ->assertJsonPath('error.meta.serving_node', 'dev-2');
    });

    it('keeps app-prod self grants from registering apps', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createTestAppHostNode(
            attributes: [
                'name' => 'prod-1',
                'host' => APP_REGISTER_CALLER_WG_IP,
                'wireguard_address' => APP_REGISTER_CALLER_WG_IP,
                'managed' => true,
            ],
            role: 'app-prod',
        );
        grantAppRegisterAccess(
            caller: $caller,
            appNode: $caller,
            permissions: app(NodePermissionPresets::class)->permissions('app-prod-self'),
        );
        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'prod-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:register')
            ->assertJsonPath('error.meta.serving_node', 'prod-1');

        expect(App::query()->count())->toBe(0)->and($remoteShell->scripts)->toBe([]);
    });

    it('stores the opt-in HTTPS runtime proxy transport when registering an app', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.42',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.42');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
                'runtime_proxy_transport' => 'https',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'https');

        expect(App::query()->where('name', 'docs')->firstOrFail()->runtime_config)
            ->toBe(['proxy_transport' => 'https']);
    });

    it('clears the opt-in HTTPS runtime proxy transport when HTTP is explicit', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.43',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.43');

        $app = App::factory()->create([
            'name' => 'docs',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);
        app_register_instance($app, 'development', $targetNode, '/home/orbit/apps/docs');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
                'runtime_proxy_transport' => 'http',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'converged')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'http');

        expect(App::query()->where('name', 'docs')->firstOrFail()->runtime_config)
            ->toBeNull();
    });

    it('rejects protected proxy routes when re-registering a concrete instance', function (string $routeType): void {
        createTestGatewayNode(['name' => 'gateway-1']);
        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.49',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.49');

        $app = App::factory()->create(['name' => 'docs']);
        $selected = app_register_instance(
            $app,
            'development',
            $targetNode,
            '/home/orbit/apps/docs',
            domain: 'docs.test',
        );
        $sibling = app_register_instance($app, 'preview', $targetNode, '/home/orbit/apps/docs-preview');
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $selected->id,
        ]);
        $attributes = match ($routeType) {
            'sibling app' => [
                'instance_id' => $sibling->id,
                'owner_type' => 'app',
                'kind' => 'app',
            ],
            'workspace' => [
                'instance_id' => $selected->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
            ],
            'analytics' => [
                'instance_id' => $selected->id,
                'owner_type' => 'app-analytics',
                'kind' => 'proxy',
            ],
            'websocket' => [
                'instance_id' => $selected->id,
                'owner_type' => 'app-websocket',
                'kind' => 'proxy',
            ],
        };
        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            ...$attributes,
        ]);
        app()->instance(RemoteShell::class, new AppRegisterApiSequencedRemoteShell([]));

        $this
            ->call(
                'POST',
                '/api/instances/register',
                [
                    'name' => 'docs.development',
                    'node' => 'app-1',
                    'path' => '/home/orbit/apps/docs',
                ],
                [],
                [],
                app_register_fallback_server(),
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.domain', 'docs.test');
    })->with([
        'sibling app',
        'workspace',
        'analytics',
        'websocket',
    ]);

    it('rejects malformed ownership on the selected instance route', function (string $invalidity): void {
        createTestGatewayNode(['name' => 'gateway-1']);
        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.49',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.49');
        $app = App::factory()->create(['name' => 'docs']);
        $selected = app_register_instance(
            $app,
            'development',
            $targetNode,
            '/home/orbit/apps/docs',
            domain: 'docs.test',
        );
        $route = ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'app_id' => $app->id,
            'instance_id' => $selected->id,
            'workspace_id' => null,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        if ($invalidity === 'missing app') {
            $route->forceFill(['app_id' => null])->saveQuietly();
        }

        if ($invalidity === 'missing instance') {
            $route->forceFill(['instance_id' => null])->saveQuietly();
        }

        if ($invalidity === 'conflicting app') {
            $route->forceFill(['app_id' => App::factory()->create()->id])->saveQuietly();
        }

        if ($invalidity === 'wrong kind') {
            $route->forceFill(['kind' => 'proxy'])->saveQuietly();
        }

        if ($invalidity === 'workspace identity') {
            $workspace = Workspace::factory()->create([
                'app_id' => $app->id,
                'instance_id' => $selected->id,
            ]);
            $route->forceFill(['workspace_id' => $workspace->id])->saveQuietly();
        }

        app()->instance(RemoteShell::class, new AppRegisterApiSequencedRemoteShell([]));

        $this
            ->call(
                'POST',
                '/api/instances/register',
                [
                    'name' => 'docs.development',
                    'node' => 'app-1',
                    'path' => '/home/orbit/apps/docs',
                ],
                [],
                [],
                app_register_fallback_server(),
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.domain', 'docs.test');
    })->with([
        'missing app',
        'missing instance',
        'conflicting app',
        'wrong kind',
        'workspace identity',
    ]);

    it('rejects invalid runtime proxy transport values before registration', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
                'runtime_proxy_transport' => 'ftp',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime_proxy_transport');

        expect(App::query()->count())->toBe(0)->and($remoteShell->scripts)->toBe([]);
    });

    it('moves only the selected instance when the dotted selector plus node and path are explicit', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $oldNode = createTestAppHostNode([
            'name' => 'old-app',
            'tld' => 'old',
            'status' => 'active',
        ]);
        $targetNode = createTestAppHostNode([
            'name' => 'new-app',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.44', '/srv/docs');
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        app_register_instance($app, 'development', $oldNode, '/home/orbit/apps/docs');
        app_register_instance($app, 'second', $targetNode, '/srv/other', domain: 'second.test');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs.development',
                'node' => 'new-app',
                'path' => '/srv/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'moved')
            ->assertJsonPath('success.data.instance.name', 'development')
            ->assertJsonPath('success.data.instance.node', 'new-app')
            ->assertJsonPath('success.data.instance.path', '/srv/docs')
            ->assertJsonMissingPath('success.data.app.path');

        $app = App::query()->where('name', 'docs')->firstOrFail();

        $development = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', 'development')
            ->firstOrFail();
        $developmentConfig = $development->driver_config;

        expect($developmentConfig)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($developmentConfig->node_id)
            ->toBe($targetNode->id)
            ->and($developmentConfig->path)
            ->toBe('/srv/docs')
            ->and($development->adopted)
            ->toBeTrue();

        $sibling = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', 'second')
            ->firstOrFail();
        $siblingConfig = $sibling->driver_config;

        expect($siblingConfig)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($siblingConfig->node_id)
            ->toBe($targetNode->id)
            ->and($siblingConfig->path)
            ->toBe('/srv/other');

        expect($remoteShell->scripts)
            ->toContainAppRegisterSourcePathProbe('/srv/docs');
    });

    it('refuses to move an existing instance when the selector is a bare app slug', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $oldNode = createTestAppHostNode([
            'name' => 'old-app',
            'tld' => 'old',
            'status' => 'active',
        ]);
        $targetNode = createTestAppHostNode([
            'name' => 'new-app',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.44', '/srv/docs');
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        app_register_instance($app, 'development', $oldNode, '/home/orbit/apps/docs');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'new-app',
                'path' => '/srv/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'app.path_collision')
            ->assertJsonPath('error.meta.existing_instance', 'docs.development')
            ->assertJsonPath('error.meta.serving_node', 'old-app');

        $app = App::query()->where('name', 'docs')->firstOrFail();
        $instance = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', 'development')
            ->firstOrFail();
        $config = $instance->driver_config;

        expect($config)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($config->node_id)
            ->toBe($oldNode->id)
            ->and($config->path)
            ->toBe('/home/orbit/apps/docs');
    });

    it('requires an instance selector when a bare slug matches multiple instances', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $oldNode = createTestAppHostNode([
            'name' => 'old-app',
            'tld' => 'old',
            'status' => 'active',
        ]);
        $targetNode = createTestAppHostNode([
            'name' => 'new-app',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.44', '/srv/docs');
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        app_register_instance($app, 'development', $oldNode, '/home/orbit/apps/docs');
        app_register_instance($app, 'second', $targetNode, '/srv/other');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'new-app',
                'path' => '/srv/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        $app = App::query()->where('name', 'docs')->firstOrFail();
        $config = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', 'development')
            ->firstOrFail()
            ->driver_config;

        expect($config)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($config->node_id)
            ->toBe($oldNode->id);
    });

    it('rejects a dotted selector that names a missing instance', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.44');
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        app_register_instance($app, 'development', $targetNode, '/home/orbit/apps/docs');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs.staging',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'name');
    });

    it('reports sibling instances that follow app-owned proxy transport changed by re-register', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
            'managed' => true,
        ]);
        $siblingNode = createTestAppHostNode([
            'name' => 'beast',
            'tld' => 'beast',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.44');
        $app = App::factory()->create([
            'name' => 'docs',
            'php_version' => '8.5',
            'runtime_config' => null,
        ]);
        app_register_instance($app, 'development', $targetNode, '/home/orbit/apps/docs');
        app_register_instance($app, 'second', $siblingNode, '/srv/docs');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs.development',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
                'runtime_proxy_transport' => 'https',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response->assertOk();

        $warnings = $response->json('success.meta.warnings');
        $fanout = array_values(array_filter(
            is_array($warnings) ? $warnings : [],
            static fn (array $warning): bool => ($warning['code'] ?? null) === 'instance.shared_runtime_policy_applied',
        ));

        expect($fanout)
            ->toHaveCount(1)
            ->and($fanout[0]['family'])
            ->toBe('instance')
            ->and($fanout[0]['message'])
            ->toContain('docs.second')
            ->and($fanout[0]['message'])
            ->toContain('beast')
            ->and($fanout[0]['message'])
            ->toContain('proxy transport https');
    });

    it('keeps omitted root and php version values on re-register', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'wireguard_address' => '10.6.0.44',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.44');
        $app = App::factory()->create([
            'name' => 'docs',
            'php_version' => '8.4',
        ]);
        app_register_instance($app, 'development', $targetNode, '/home/orbit/apps/docs', 'web');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.4', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response->assertOk();

        $app = App::query()->where('name', 'docs')->firstOrFail();
        $config = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', 'development')
            ->firstOrFail()
            ->driver_config;

        expect($config)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($config->document_root)
            ->toBe('web')
            ->and($app->php_version)
            ->toBe('8.4');
    });

    it('allows an app-dev node to re-register an existing app with a domain under its development tld', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'nmbp',
            'status' => 'active',
        ], settings: ['tld' => 'nmbp']);
        grantAppRegisterAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'happie-nmbp',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);
        app_register_instance(
            $app,
            'development',
            $targetNode,
            '/Users/nckrtl/apps/happie',
            'public',
            'happie-nmbp.nmbp',
        );

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'path' => '/Users/nckrtl/apps/happie',
                            'exists' => true,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'happie-nmbp',
                'node' => 'app-1',
                'path' => '/Users/nckrtl/apps/happie',
                'domain' => 'happie.nmbp',
                'runtime_proxy_transport' => 'https',
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'partial')
            ->assertJsonPath('success.data.instance.url', 'https://happie.nmbp')
            ->assertJsonMissingPath('success.data.app.url')
            ->assertJsonPath('success.meta.warnings.0.code', 'proxy.enactment_failed')
            ->assertJsonPath('success.meta.warnings.0.node', 'app-1')
            ->assertJsonPath('success.meta.warnings.0.operation', 'runtime_trust_pool.ensure');

        $app = App::query()->where('name', 'happie-nmbp')->firstOrFail();
        $config = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', 'development')
            ->firstOrFail()
            ->driver_config;

        expect($config)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($config->domain)
            ->toBe('happie.nmbp')
            ->and($remoteShell->scripts[0])
            ->toContain("internal:app-source-path:probe '/Users/nckrtl/apps/happie'");
    });

    it('rejects registration when the caller lacks instance:register on the target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode, ['instance:read']);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:register')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->count())->toBe(0)->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects omitted-node registration when the caller cannot access the inferred target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        createAppRegisterCallerNode();
        createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:register')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->count())->toBe(0)->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects production registration when the target node lacks the app-prod role', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
                'domain' => 'docs.example.com',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'app.ineligible_node')
            ->assertJsonPath('error.meta.required_role', 'app-prod');

        expect(App::query()->count())->toBe(0)->and($remoteShell->scripts)->toBe([]);
    });

    it('allows database-role callers when instance:register is granted on the target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'database',
            'status' => 'active',
        ]);
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.45',
            'managed' => true,
        ]);
        grantAppRegisterAccess($caller, $targetNode);
        fake_app_register_source_path_probe('10.6.0.45');

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/instances/register',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
            ],
            [],
            [],
            app_register_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.app.name', 'docs');

        expect(App::query()->where('name', 'docs')->exists())
            ->toBeTrue();

        expect($remoteShell->scripts)
            ->toContainAppRegisterSourcePathProbe('/home/orbit/apps/docs');
    });
});

/** @mago-expect lint:excessive-parameter-list */
function app_register_instance(
    App $app,
    string $name,
    Node $node,
    string $path,
    string $documentRoot = 'public',
    ?string $domain = null,
): Instance {
    return Instance::factory()->for($app)->create([
        'name' => $name,
        'adopted' => true,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path,
            document_root: $documentRoot,
            domain: $domain,
        ),
    ]);
}

function fake_app_register_source_path_probe(
    string $address,
    string $path = '/home/orbit/apps/docs',
    bool $exists = true,
): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($address, $path, $exists): mixed {
        if ($request->url() !== "http://{$address}:9477/v1/commands") {
            return Http::response([], 404);
        }

        $commandName = $request['argv'][0] ?? null;
        $action = $request['argv'][1] ?? null;
        $data = match (true) {
            $commandName === 'internal:managed-file' && $action === 'probe' => [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ],
            $commandName === 'internal:managed-file' && $action === 'write' => [
                'outcome' => 'written',
            ],
            default => [
                'path' => $path,
                'exists' => $exists,
            ],
        };

        return Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'app-source-path.probe',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => $data,
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ]);
    });
}

function app_register_source_path_probe_was_sent(Request $request, string $address, string $path): bool
{
    return (
        $request->url() === "http://{$address}:9477/v1/commands"
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:app-source-path:probe'
        && $request['argv'][1] === $path
    );
}

final class AppRegisterApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<string>
     */
    public array $nodeNames = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->nodeNames[] = $node->name;

        if (
            str_contains($script, "internal:app-source-path:probe '/home/orbit/apps/docs'")
            || str_contains($script, "internal:app-source-path:probe '/srv/docs'")
        ) {
            $path = str_contains($script, "'/srv/docs'") ? '/srv/docs' : '/home/orbit/apps/docs';

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'path' => $path,
                            'exists' => true,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        if (str_contains($script, "internal:caddy-config 'read-global'")) {
            return app_register_shell_success(['content' => '']);
        }

        if (str_contains($script, "internal:managed-file 'probe'")) {
            return app_register_shell_success([
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]);
        }

        if (str_contains($script, "internal:caddy-config 'write-site'")) {
            return app_register_shell_success(['path' => '/etc/caddy/sites/docs.test.caddy']);
        }

        if (str_contains($script, "internal:caddy-config 'reload'")) {
            return app_register_shell_success(['container' => 'orbit-caddy']);
        }

        return (
            array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            )
        );
    }
}

expect()->extend('toContainAppRegisterSourcePathProbe', function (string $path): void {
    expect(collect($this->value)
        ->contains(
            fn (string $script): bool => str_contains($script, "internal:app-source-path:probe '{$path}'"),
        ))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $data
 */
function app_register_shell_success(array $data): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR)
            ."\n",
        stderr: '',
        durationMs: 1,
    );
}
