<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProxyRoute;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Proxy\RemoteCaddyConfig;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(OrbitCaService::class, new EnsureAppProxyRouteTestCa);
    app()->instance(
        RemoteCaddyConfig::class,
        new RemoteCaddyConfig(app(RemoteLocalExecutor::class)),
    );
});

afterEach(function (): void {});

final class EnsureAppProxyRouteTestShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class EnsureAppProxyRouteTestCertificateInstaller implements SiteCertificateInstaller
{
    /** @var list<string> */
    public array $hosts = [];

    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $domain): array
    {
        $this->hosts[] = $domain;

        return $this->expectedPathsFor($node, $domain);
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node, string $domain): array
    {
        return [
            'cert' => "/home/orbit/.config/orbit/certs/{$domain}.crt",
            'key' => "/home/orbit/.config/orbit/certs/{$domain}.key",
        ];
    }
}

final readonly class EnsureAppProxyRouteTestCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }
}

final class EnsureAppProxyRouteTestInternalExecutor implements RunsInternalCommands
{
    /** @var list<array{node: string, action: string, payload: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly ?string $failedNode = null,
        private readonly ?string $failedAction = null,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $input = $transportOptions['input'] ?? '{}';
        $payload = is_string($input)
            ? json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR)
            : [];
        $this->calls[] = [
            'node' => $node->name,
            'action' => $action,
            'payload' => is_array($payload) ? $payload : [],
        ];

        if ($node->name === $this->failedNode && $action === $this->failedAction) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1);
        }

        $data = match ($action) {
            'read-global' => ['content' => new CaddyGlobalConfig()->fresh()],
            'write-site' => ['path' => "/etc/caddy/sites/{$node->name}.caddy"],
            'reload' => ['container' => 'orbit-caddy'],
            default => [],
        };

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}

it('creates a PHP app proxy route targeting the FrankenPHP runtime container', function (): void {
    Node::factory()->gateway()->create(['wireguard_address' => '10.47.0.2']);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'tld' => 'test',
            'wireguard_address' => '10.47.0.31',
        ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '',
            document_root: 'public',
            domain: "{$app->name}.{$node->tld}",
        ),
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.31:9477/v1/commands' => Http::sequence()
            ->push(ensure_app_proxy_route_agent_response('managed-file.probe', [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]))
            ->push(ensure_app_proxy_route_agent_response('managed-file.write', [
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash(algo: 'sha256', data: 'fake-root-ca'),
                'mode' => '0644',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/docs.test.caddy',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    app(EnsureAppProxyRoute::class)->handle($app, $instance);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $requests = ensure_app_proxy_route_agent_requests('10.47.0.31');
    $managedFilePayload = json_decode(
        (string) ($requests[1]['input'] ?? ''),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sitePayload = json_decode((string) ($requests[3]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
    $caddySite = (string) ($sitePayload['content'] ?? '');

    expect($route->domain)
        ->toBe('docs.test')
        ->and($route->instance_id)
        ->toBe($instance->id)
        ->and($route->instance?->is($instance))
        ->toBeTrue()
        ->and($instance->proxyRoutes()->whereKey($route->getKey())->exists())
        ->toBeTrue()
        ->and($route->config['runtime_upstream'])
        ->toBe('http://orbit-app-docs-development:8080')
        ->and($route->config['runtime_upstream_tls'] ?? null)
        ->toBeNull()
        ->and($route->config['php_socket'])
        ->toBeNull()
        ->and($route->config['tls'])
        ->toBe([
            'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
            'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
        ])
        ->and($caddySite)
        ->toContain('tls /home/orbit/.config/orbit/certs/docs.test.crt /home/orbit/.config/orbit/certs/docs.test.key')
        ->and($caddySite)
        ->toContain('reverse_proxy http://orbit-app-docs-development:8080')
        ->and($caddySite)
        ->toContain("uri /api/runtime-activations/app-instance/{$instance->id}")
        ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
        ->toContain('lb_try_duration 15s')
        ->toContain('lb_try_interval 250ms')
        ->and($caddySite)
        ->not->toContain('tls_server_name docs.test')->and($caddySite)
        ->not->toContain('php_fastcgi')->and($caddySite)
        ->not->toContain('file_server');
    expect($requests)
        ->toHaveCount(5)
        ->and($requests[0]['argv'][0] ?? null)
        ->toBe('internal:managed-file')
        ->and($requests[0]['argv'][1] ?? null)
        ->toBe('probe')
        ->and($requests[1]['argv'][1] ?? null)
        ->toBe('write')
        ->and($managedFilePayload)
        ->toMatchArray([
            'path' => '/etc/orbit/ca/root.crt',
            'content' => 'fake-root-ca',
            'mode' => '0644',
            'directory_mode' => '0755',
        ])
        ->and($requests[3]['argv'][1] ?? null)
        ->toBe('write-site')
        ->and($sitePayload)
        ->toMatchArray(['domain' => 'docs.test'])
        ->and($requests[4]['argv'][1] ?? null)
        ->toBe('reload');
});

it('routes the explicitly selected instance, not the primary or a stale app environment column', function (): void {
    Node::factory()->gateway()->create(['wireguard_address' => '10.47.0.2']);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'tld' => 'test',
            'wireguard_address' => '10.47.0.34',
        ]);
    // Stale app-level column claims production; the concrete instance decides.
    $app = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    // Primary (first) Orbit instance the app-level route would otherwise pick.
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
    // The explicitly selected, non-primary instance whose placement must win.
    $selected = Instance::factory()->for($app)->create([
        'name' => 'preview',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs-preview',
            document_root: 'public',
            domain: 'docs-preview.test',
        ),
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.34:9477/v1/commands' => Http::sequence()
            ->push(ensure_app_proxy_route_agent_response('managed-file.probe', [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]))
            ->push(ensure_app_proxy_route_agent_response('managed-file.write', [
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash(algo: 'sha256', data: 'fake-root-ca'),
                'mode' => '0644',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/docs-preview.test.caddy',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    app(EnsureAppProxyRoute::class)->handle($app, $selected);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();

    expect($route->domain)
        ->toBe('docs-preview.test')
        ->and($route->instance_id)
        ->toBe($selected->id)
        ->and($route->config['runtime_upstream'])
        ->toContain('preview')
        ->and($route->config['runtime_upstream'])
        ->not->toContain('development');
});

it('creates a static app proxy route with file_server', function (): void {
    Node::factory()->gateway()->create(['wireguard_address' => '10.47.0.2']);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'tld' => 'test',
            'wireguard_address' => '10.47.0.32',
        ]);
    $app = App::factory()
        ->static()
        ->create([
            'name' => 'marketing',
        ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '',
            document_root: 'public',
            domain: "{$app->name}.{$node->tld}",
        ),
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.32:9477/v1/commands' => Http::sequence()
            ->push(ensure_app_proxy_route_agent_response('managed-file.probe', [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]))
            ->push(ensure_app_proxy_route_agent_response('managed-file.write', [
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash(algo: 'sha256', data: 'fake-root-ca'),
                'mode' => '0644',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/marketing.test.caddy',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    app(EnsureAppProxyRoute::class)->handle($app, $instance);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $requests = ensure_app_proxy_route_agent_requests('10.47.0.32');
    $sitePayload = json_decode((string) ($requests[3]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
    $caddySite = (string) ($sitePayload['content'] ?? '');

    expect($route->domain)
        ->toBe('marketing.test')
        ->and($route->config['runtime_upstream'])
        ->toBeNull()
        ->and($route->config['php_socket'])
        ->toBeNull()
        ->and($caddySite)
        ->toContain('file_server')
        ->and($caddySite)
        ->toContain('root * /public')
        ->and($caddySite)
        ->toContain("uri /api/runtime-activations/app-instance/{$instance->id}")
        ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
        ->and($caddySite)
        ->not->toContain('php_fastcgi')->and($caddySite)
        ->not->toContain('reverse_proxy');
    expect($requests)
        ->toHaveCount(5)
        ->and($requests[3]['argv'][0] ?? null)
        ->toBe('internal:caddy-config')
        ->and($requests[3]['argv'][1] ?? null)
        ->toBe('write-site')
        ->and($sitePayload)
        ->toMatchArray(['domain' => 'marketing.test']);
});

it('installs app-dev runtime trust pool through the managed file agent path', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'tld' => 'test',
            'wireguard_address' => '10.47.0.33',
        ]);
    $app = App::factory()->create([
        'name' => 'api',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '',
            document_root: 'public',
            domain: "{$app->name}.{$node->tld}",
        ),
    ]);

    app()->instance(RemoteShell::class, new EnsureAppProxyRouteTestShell);
    app()->instance(SiteCertificateInstaller::class, new EnsureAppProxyRouteTestCertificateInstaller);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.33:9477/v1/commands' => Http::sequence()
            ->push(ensure_app_proxy_route_agent_response('managed-file.probe', [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]))
            ->push(ensure_app_proxy_route_agent_response('managed-file.write', [
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash(algo: 'sha256', data: 'fake-root-ca'),
                'mode' => '0644',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.read-global', [
                'content' => new CaddyGlobalConfig()->fresh(),
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/api.test.caddy',
            ]))
            ->push(ensure_app_proxy_route_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    app(EnsureAppProxyRoute::class)->handle($app, $instance);

    $requests = ensure_app_proxy_route_agent_requests('10.47.0.33');
    $managedFilePayload = json_decode(
        (string) ($requests[1]['input'] ?? ''),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sitePayload = json_decode((string) ($requests[3]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($requests)
        ->toHaveCount(5)
        ->and(array_slice($requests[0]['argv'] ?? [], offset: 0, length: 2))
        ->toBe(['internal:managed-file', 'probe'])
        ->and(array_slice($requests[1]['argv'] ?? [], offset: 0, length: 2))
        ->toBe(['internal:managed-file', 'write'])
        ->and($managedFilePayload)
        ->toMatchArray([
            'path' => '/etc/orbit/ca/root.crt',
            'content' => 'fake-root-ca',
            'mode' => '0644',
            'directory_mode' => '0755',
        ])
        ->and(array_slice($requests[3]['argv'] ?? [], offset: 0, length: 2))
        ->toBe(['internal:caddy-config', 'write-site'])
        ->and((string) ($sitePayload['content'] ?? ''))
        ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
        ->toContain('tls_server_name api.test');
});

it('removes stale app-owned proxy routes for the same app when its domain changes', function (): void {
    $node = Node::factory()->appDev(['tld' => 'nmbp'])->create(['tld' => 'nmbp']);
    $app = App::factory()->create([
        'name' => 'happie-nmbp',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    ProxyRoute::factory()->create([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'happie-nmbp.nmbp',
        'config' => [
            'instance' => [
                'id' => $instance->id,
                'name' => $instance->name,
                'selector' => 'happie-nmbp.nmbp',
                'domain' => 'happie-nmbp.nmbp',
                'node' => $node->name,
                'node_id' => $node->id,
            ],
        ],
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    $executor = new EnsureAppProxyRouteTestInternalExecutor;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    app()->instance(RemoteCaddyConfig::class, new RemoteCaddyConfig($executor));

    app(EnsureAppProxyRoute::class)->handle($app, $instance);

    expect(ProxyRoute::query()->where('app_id', $app->id)->pluck('domain')->all())
        ->toBe(['happie.nmbp'])
        ->and(array_column($executor->calls, 'action'))
        ->toContain('remove-site')
        ->and(collect($executor->calls)->firstWhere('action', 'remove-site')['payload'])
        ->toMatchArray(['domain' => 'happie-nmbp.nmbp']);
});

it('converges production proxy artifacts in backend router ingress order', function (): void {
    [$app, $instance, $backend, $router, $ingress] = ensure_app_proxy_route_production_topology();

    app()->instance(RemoteShell::class, new EnsureAppProxyRouteTestShell);
    app()->instance(SiteCertificateInstaller::class, new EnsureAppProxyRouteTestCertificateInstaller);
    $executor = new EnsureAppProxyRouteTestInternalExecutor;
    app()->instance(RemoteCaddyConfig::class, new RemoteCaddyConfig($executor));

    $warnings = app(EnsureAppProxyRoute::class)->handle($app, $instance);
    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $backendContent = $executor->calls[1]['payload']['content'] ?? null;

    expect(array_column($executor->calls, 'node'))
        ->toBe([
            'main1',
            'main1',
            'main1',
            'gateway-router',
            'gateway-router',
            'gateway-router',
            'public-ingress',
            'public-ingress',
            'public-ingress',
        ])
        ->and($route->config['target'])
        ->toBe([
            'type' => 'instance',
            'value' => 'hauzer.production',
        ])
        ->and($route->config['instance'])
        ->toMatchArray([
            'name' => 'production',
            'selector' => 'hauzer.production',
            'domain' => 'hauzer.app',
            'node' => 'main1',
            'node_id' => $backend->id,
        ])
        ->and($route->config['backend_artifacts'][0]['runtime_upstream'])
        ->toBe('http://orbit-app-hauzer-production:8080')
        ->and($backendContent)
        ->toContain('reverse_proxy http://orbit-app-hauzer-production:8080')
        ->and($route->config['enactment']['status'] ?? null)
        ->toBe('converged')
        ->and($route->config['enactment']['failure'])
        ->toBeNull()
        ->and(collect($warnings)->contains(
            fn (array $warning): bool => ($warning['code'] ?? null) === 'proxy.enactment_failed',
        ))
        ->toBeFalse()
        ->and(collect($warnings)->firstWhere('code', 'proxy.domain_inactive'))
        ->toMatchArray([
            'message' => "Production domain 'hauzer.app' is not yet active. Retry with 'orbit instance:register hauzer.production --domain=hauzer.app' once DNS has propagated.",
            'next_command' => 'instance:register hauzer.production --domain=hauzer.app',
        ]);
});

it('records partial production enactment and identifies the failed node and operation', function (): void {
    [$app, $instance, $backend, $router, $ingress] = ensure_app_proxy_route_production_topology();

    app()->instance(RemoteShell::class, new EnsureAppProxyRouteTestShell);
    app()->instance(SiteCertificateInstaller::class, new EnsureAppProxyRouteTestCertificateInstaller);
    $executor = new EnsureAppProxyRouteTestInternalExecutor(
        failedNode: 'gateway-router',
        failedAction: 'write-site',
    );
    app()->instance(RemoteCaddyConfig::class, new RemoteCaddyConfig($executor));

    $warnings = app(EnsureAppProxyRoute::class)->handle($app, $instance);
    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $warning = collect($warnings)->firstWhere('code', 'proxy.enactment_failed');

    expect($route->config['enactment']['status'] ?? null)
        ->toBe('partial')
        ->and($route->config['enactment']['failure'] ?? null)
        ->toBe([
            'layer' => 'router',
            'node' => 'gateway-router',
            'operation' => 'caddy.router.install',
        ])
        ->and($warning)
        ->toMatchArray([
            'node' => 'gateway-router',
            'operation' => 'caddy.router.install',
            'layer' => 'router',
        ])
        ->and(array_column($executor->calls, 'node'))
        ->not->toContain('public-ingress');
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function ensure_app_proxy_route_agent_response(string $operationId, array $data, int $exitCode = 0): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

/**
 * @return list<Request>
 */
function ensure_app_proxy_route_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): array => $record[0]->data())
        ->values()
        ->all();
}

/**
 * @return array{App, Instance, Node, Node, Node}
 */
function ensure_app_proxy_route_production_topology(): array
{
    $ingress = Node::factory()
        ->ingress()
        ->managed()
        ->create([
            'name' => 'public-ingress',
            'wireguard_address' => '10.47.1.10',
        ]);
    $router = Node::factory()
        ->router()
        ->managed()
        ->create([
            'name' => 'gateway-router',
            'wireguard_address' => '10.47.1.20',
        ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $router->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
    $backend = Node::factory()
        ->managed()
        ->create([
            'name' => 'main1',
            'wireguard_address' => '10.47.1.30',
        ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $backend->id,
        'role' => 'app-prod',
        'status' => 'active',
        'settings' => ['ingress_node_id' => $ingress->id],
    ]);
    $app = App::factory()->create([
        'name' => 'hauzer',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $backend->id,
            node: $backend->name,
            path: '/home/orbit/apps/hauzer',
            document_root: 'public',
            domain: 'hauzer.app',
        ),
    ]);

    return [$app, $instance, $backend, $router, $ingress];
}
