<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_STORE_CALLER_WG_IP = '10.6.0.77';

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
    app()->instance(OrbitCaService::class, new AppStoreControllerTestCa);
});

final readonly class AppStoreControllerTestCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }
}

function createAppStoreCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_STORE_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppStoreAccess(Node $caller, Node $appNode, array $permissions = ['app:new']): void
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

function assignAppStoreRole(
    Node $node,
    string $role,
    string $status = 'active',
    array $settings = [],
): NodeRoleAssignment {
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

/**
 * @return array<string, string>
 */
function app_store_fallback_server(): array
{
    return [
        'REMOTE_ADDR' => APP_STORE_CALLER_WG_IP,
    ];
}

describe('AppStoreController', function (): void {
    it('rejects incomplete or conflicting source plans before remote work', function (array $source): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                ...$source,
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'source')
            ->assertJsonPath('error.meta.fields', [
                'repository',
                'template_repository',
                'new_repository',
            ]);

        expect(App::query()->count())->toBe(0)->and($remoteShell->runs)->toBe([]);
    })->with([
        'missing source' => [[]],
        'template without destination' => [[
            'template_repository' => 'hardimpact/laravel-template',
        ]],
        'destination without template' => [[
            'new_repository' => 'hardimpact/docs',
        ]],
        'clone and template branches' => [[
            'repository' => 'hardimpact/docs',
            'template_repository' => 'hardimpact/laravel-template',
            'new_repository' => 'hardimpact/new-docs',
        ]],
    ]);

    it('identifies the malformed template source field before remote work', function (
        array $source,
        string $field,
    ): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                ...$source,
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);

        expect(App::query()->count())->toBe(0)->and($remoteShell->runs)->toBe([]);
    })->with([
        'non-GitHub template' => [
            [
                'template_repository' => 'https://gitlab.example.com/hardimpact/laravel-template.git',
                'new_repository' => 'hardimpact/docs',
            ],
            'template_repository',
        ],
        'GitHub template URL instead of shorthand' => [
            [
                'template_repository' => 'https://github.com/hardimpact/laravel-template.git',
                'new_repository' => 'hardimpact/docs',
            ],
            'template_repository',
        ],
        'invalid new repository' => [
            [
                'template_repository' => 'hardimpact/laravel-template',
                'new_repository' => 'https://github.com/hardimpact/docs.git',
            ],
            'new_repository',
        ],
    ]);

    it('rejects credential-bearing clone URLs before remote work', function (string $repository): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => $repository,
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'repository');

        expect(App::query()->count())->toBe(0)->and($remoteShell->runs)->toBe([]);
    })->with([
        'token in HTTPS username' => ['https://secret-token@git.example.com/docs.git'],
        'HTTPS username and password' => ['https://user:secret@git.example.com/docs.git'],
        'SSH password' => ['ssh://git:secret@git.example.com/docs.git'],
        'token in query string' => ['https://git.example.com/docs.git?token=secret'],
    ]);

    it('creates app source and registry intent for authorized callers', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
                'root' => 'public',
                'php_version' => '8.4',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.data.app.php_version', '8.4')
            ->assertJsonPath('success.data.app.runtime', 'php')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'http')
            ->assertJsonMissingPath('success.data.app.worker_enabled')
            ->assertJsonMissingPath('success.data.app.worker_config')
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())
            ->toBeTrue()
            ->and(collect($remoteShell->runs)
                ->pluck('script')
                ->contains(
                    fn (string $script): bool => str_contains(
                        $script,
                        "> '/home/orbit/.config/orbit/ca/root.crt'",
                    ),
                ))
            ->toBeTrue();
    });

    it('creates a template source without forwarding the stored destination as a clone source', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'template_repository' => 'hardimpact/laravel-template',
                'new_repository' => 'hardimpact/docs',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.repository', 'git@github.com:hardimpact/docs.git');

        $sourceScript = collect($remoteShell->runs)
            ->pluck('script')
            ->first(fn (string $script): bool => str_contains($script, 'internal:app-source:create'));

        expect($sourceScript)
            ->toBeString()
            ->toContain("--template-repository='hardimpact/laravel-template'")
            ->toContain("--new-repository='hardimpact/docs'")
            ->not
            ->toContain('--repository=')
            ->and(App::query()->where('name', 'docs')->firstOrFail()->repository)
            ->toBe('git@github.com:hardimpact/docs.git');
    });

    it('stores the opt-in HTTPS runtime proxy transport for new apps', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        app()->instance(RemoteShell::class, new AppStoreRecordingRemoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
                'root' => 'public',
                'php_version' => '8.4',
                'runtime_proxy_transport' => 'https',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.runtime', 'php')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'https');

        expect(App::query()->where('name', 'docs')->firstOrFail()->runtime_config)
            ->toBe(['proxy_transport' => 'https']);
    });

    it('rejects invalid runtime proxy transport values before creating the app', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
                'runtime_proxy_transport' => 'ftp',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime_proxy_transport');

        expect(App::query()->count())->toBe(0)->and($remoteShell->runs)->toBe([]);
    });

    it('rejects app creation when the caller lacks app:new on the target app node', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode, ['app:read']);

        $remoteShell = new AppStoreRecordingRemoteShell(scriptResults: [
            "id -u 'docs'" => new RemoteShellResult(
                exitCode: 0,
                stdout: "1001\n1002\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:new')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->count())->toBe(0)->and($remoteShell->runs)->toBe([]);
    });

    it('allows database-role callers when app:new is granted on the target app node', function (): void {
        $caller = createAppStoreCallerNode();
        assignAppStoreRole($caller, 'database');
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
                'root' => 'public',
                'php_version' => '8.5',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.app.name', 'docs');

        expect(App::query()->where('name', 'docs')->exists())
            ->toBeTrue()
            ->and($remoteShell->runs)
            ->not->toBe([]);
    });

    it('rejects app creation before remote work when the proxy route domain is already registered', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
        ]);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertConflict()
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.domain', 'docs.test');

        expect(App::query()->count())->toBe(0)->and($remoteShell->runs)->toBe([]);
    });

    it('reports github transport when github source creation fails', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell(new RemoteShellResult(
            exitCode: 128,
            stdout: '',
            stderr: "permission denied\n",
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertServerError()
            ->assertJsonPath('error.code', 'app.source_creation_failed')
            ->assertJsonPath('error.meta.reason', 'permission denied')
            ->assertJsonPath('error.meta.transport', 'github');

        expect(App::query()->where('name', 'docs')->exists())
            ->toBeFalse()
            ->and($remoteShell->runs[0]['script'])
            ->toContain(
                "internal:app-source:create 'orbit' '/home/orbit/apps/docs' --repository='git@github.com:hardimpact/docs.git'",
            );
    });

    it('creates production app routes on ingress and backend app nodes', function (): void {
        $caller = createAppStoreCallerNode();
        $router = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignAppStoreRole($router, 'router');
        $ingress = Node::factory()->create([
            'name' => 'edge-1',
            'status' => 'active',
        ]);
        assignAppStoreRole($ingress, 'ingress');

        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.21',
            'user' => 'orbit',
        ]);
        assignAppStoreRole($targetNode, 'app-prod', settings: ['ingress_node_id' => $ingress->id]);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell(scriptResults: [
            "id -u 'docs'" => new RemoteShellResult(
                exitCode: 0,
                stdout: "1001\n1002\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $response = $this->call(
            'POST',
            '/api/apps',
            [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
                'domain' => 'docs.example.com',
                'root' => 'public',
                'php_version' => '8.5',
            ],
            [],
            [],
            app_store_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.url', 'https://docs.example.com')
            ->assertJsonPath('success.meta.warnings.0.code', 'proxy.domain_inactive');

        expect(App::query()->where('name', 'docs')->value('environment'))->toBe('production');

        $route = ProxyRoute::query()->where('domain', 'docs.example.com')->firstOrFail();

        expect($route->node_id)
            ->toBe($ingress->id)
            ->and($route->config['placement'])
            ->toBe('ingress')
            ->and($route->config['ingress_node_id'])
            ->toBe($ingress->id)
            ->and($route->config['router_upstream'])
            ->toBe([
                'node_id' => $router->id,
                'node' => 'gateway-1',
                'url' => 'http://10.6.0.2:80',
            ])
            ->and($route->config['router_artifact']['node_id'])
            ->toBe($router->id)
            ->and($route->config['router_artifact']['source_hash'])
            ->toHaveLength(64)
            ->and($route->config['router_backend_pool'])
            ->toBe([
                [
                    'node_id' => $targetNode->id,
                    'node' => 'app-1',
                    'url' => 'http://10.6.0.21:8081',
                ],
            ])
            ->and($route->config['backend_artifacts'][0]['node_id'])
            ->toBe($targetNode->id)
            ->and($route->config['backend_artifacts'][0]['bind'])
            ->toBe('10.6.0.21')
            ->and($route->config['backend_artifacts'][0]['document_root'])
            ->toBe('/home/docs/app/public')
            ->and($route->config['backend_artifacts'][0]['runtime_upstream'])
            ->toBe('http://orbit-app-docs-production:8080')
            ->and($route->config['backend_artifacts'][0]['php_socket'])
            ->toBeNull()
            ->and($route->config['backend_artifacts'][0]['source_hash'])
            ->toHaveLength(64)
            ->and($route->config['target'])
            ->toBe([
                'type' => 'app_instance',
                'value' => 'docs.production',
            ])
            ->and($route->config['app_instance'])
            ->toMatchArray([
                'name' => 'production',
                'selector' => 'docs.production',
                'domain' => 'docs.example.com',
                'node' => 'app-1',
                'node_id' => $targetNode->id,
            ])
            ->and($route->source_hash)
            ->toHaveLength(64)
            ->and(collect($remoteShell->runs)->pluck('node')->all())
            ->toContain($ingress->id, $router->id, $targetNode->id)
            ->and(collect($remoteShell->runs)
                ->pluck('script')
                ->contains(
                    fn (string $script): bool => str_contains($script, "internal:caddy-config 'write-site'"),
                ))
            ->toBeTrue()
            ->and(collect($remoteShell->runs)
                ->contains(
                    fn (array $run): bool => (
                        str_contains((string) ($run['options']['input'] ?? ''), 'docs.example.com')
                        && str_contains((string) ($run['options']['input'] ?? ''), 'backend')
                    ),
                ))
            ->toBeTrue();
    });
});

final class AppStoreRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    /**
     * @param  array<string, RemoteShellResult>  $scriptResults
     */
    public function __construct(
        private readonly ?RemoteShellResult $result = null,
        private readonly array $scriptResults = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];

        foreach ($this->scriptResults as $needle => $result) {
            if (str_contains($script, $needle)) {
                return $result;
            }
        }

        if ($this->result instanceof RemoteShellResult && str_contains($script, 'internal:app-source:create')) {
            return $this->result;
        }

        if (str_contains($script, 'internal:app-source:create')) {
            return app_store_shell_success([
                'path' => '/home/orbit/apps/docs',
                'repository' => 'git@github.com:hardimpact/docs.git',
            ]);
        }

        if (str_contains($script, "internal:managed-file 'probe'")) {
            return app_store_shell_success([
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]);
        }

        if (str_contains($script, "internal:managed-file 'write'")) {
            return app_store_shell_success([
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash('sha256', 'fake-root-ca'),
                'mode' => '0644',
            ]);
        }

        if (str_contains($script, "internal:caddy-config 'read-global'")) {
            return app_store_shell_success(['content' => '']);
        }

        if (str_contains($script, "internal:caddy-config 'write-site'")) {
            return app_store_shell_success(['path' => '/etc/caddy/sites/docs.test.caddy']);
        }

        if (str_contains($script, "internal:caddy-config 'reload'")) {
            return app_store_shell_success(['container' => 'orbit-caddy']);
        }

        return (
            $this->result ?? new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            )
        );
    }
}

/**
 * @param  array<string, mixed>  $data
 */
function app_store_shell_success(array $data): RemoteShellResult
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
