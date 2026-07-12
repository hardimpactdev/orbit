<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
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
            app(RemoteShell::class),
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
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
function grantAppRegisterAccess(Node $caller, Node $appNode, array $permissions = ['app:register']): void
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
        'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE' => ExplicitRemoteShellFallback::REQUIRED,
    ];
}

describe('AppRegisterController', function (): void {
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
            '/api/apps/register',
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
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.data.app.runtime', 'php')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'http')
            ->assertJsonPath('success.data.app.worker_enabled', false)
            ->assertJsonPath('success.data.app.worker_config', null)
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
            '/api/apps/register',
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
            ->assertJsonPath('success.data.app.node', 'dev-1');

        $denied = $this->call(
            'POST',
            '/api/apps/register',
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
            ->assertJsonPath('error.meta.missing_permission', 'app:register')
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
            '/api/apps/register',
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
            ->assertJsonPath('error.meta.missing_permission', 'app:register')
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
            '/api/apps/register',
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

        App::factory()->for($targetNode, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'runtime_config' => ['proxy_transport' => 'https'],
            'adopted' => true,
        ]);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps/register',
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
            '/api/apps/register',
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

    it('moves an existing app when node and path are explicit', function (): void {
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
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $oldNode->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'adopted' => true,
        ]);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps/register',
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
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'moved')
            ->assertJsonPath('success.data.app.node', 'new-app')
            ->assertJsonPath('success.data.app.path', '/srv/docs');

        $app = App::query()->where('name', 'docs')->firstOrFail();

        expect($app->node_id)
            ->toBe($targetNode->id)
            ->and($app->path)
            ->toBe('/srv/docs')
            ->and($app->adopted)
            ->toBeTrue()
            ->and($remoteShell->nodeNames[0])
            ->toBe('new-app');

        expect($remoteShell->scripts)
            ->toContainAppRegisterSourcePathProbe('/srv/docs');
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

        App::factory()->for($targetNode, 'node')->create([
            'name' => 'happie-nmbp',
            'path' => '/Users/nckrtl/apps/happie',
            'document_root' => 'public',
            'domain' => 'happie-nmbp.nmbp',
            'environment' => 'development',
            'runtime_config' => ['proxy_transport' => 'https'],
            'adopted' => true,
        ]);

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
            '/api/apps/register',
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
            ->assertJsonPath('success.data.result.action', 'converged')
            ->assertJsonPath('success.data.app.url', 'https://happie.nmbp');

        $app = App::query()->where('name', 'happie-nmbp')->firstOrFail();

        expect($app->environment)
            ->toBe('development')
            ->and($app->domain)
            ->toBe('happie.nmbp')
            ->and($remoteShell->scripts[0])
            ->toContain("internal:app-source-path:probe '/Users/nckrtl/apps/happie'");
    });

    it('rejects registration when the caller lacks app:register on the target app node', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode, ['app:read']);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps/register',
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
            ->assertJsonPath('error.meta.missing_permission', 'app:register')
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
            '/api/apps/register',
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
            ->assertJsonPath('error.meta.missing_permission', 'app:register')
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
            '/api/apps/register',
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

    it('allows database-role callers when app:register is granted on the target app node', function (): void {
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
            '/api/apps/register',
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

function fake_app_register_source_path_probe(
    string $address,
    string $path = '/home/orbit/apps/docs',
    bool $exists = true,
): void {
    Http::preventStrayRequests();
    Http::fake([
        "http://{$address}:9477/v1/commands" => Http::response([
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
                            'data' => [
                                'path' => $path,
                                'exists' => $exists,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ]),
    ]);
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
