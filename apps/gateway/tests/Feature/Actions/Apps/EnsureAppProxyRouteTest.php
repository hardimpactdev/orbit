<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProxyRoute;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, NodeTransportPreference::AgentPush->value);

    app()->instance(OrbitCaService::class, new EnsureAppProxyRouteTestCa);
});

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

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

it('creates a PHP app proxy route targeting the FrankenPHP runtime container', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'tld' => 'test',
            'wireguard_address' => '10.47.0.31',
        ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'document_root' => 'public',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.31:9477/v1/commands' => Http::sequence()
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

    app(EnsureAppProxyRoute::class)->handle($app);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $requests = ensure_app_proxy_route_agent_requests('10.47.0.31');
    $sitePayload = json_decode((string) ($requests[1]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
    $caddySite = (string) ($sitePayload['content'] ?? '');

    expect($route->domain)
        ->toBe('docs.test')
        ->and($route->config['runtime_upstream'])
        ->toBe('http://orbit-app-docs:8080')
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
        ->toContain('reverse_proxy http://orbit-app-docs:8080')
        ->and($caddySite)
        ->not->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')->and($caddySite)
        ->not->toContain('tls_server_name docs.test')->and($caddySite)
        ->not->toContain('php_fastcgi')->and($caddySite)
        ->not->toContain('file_server');
    expect($requests)
        ->toHaveCount(3)
        ->and($requests[0]['argv'][0] ?? null)
        ->toBe('internal:caddy-config')
        ->and($requests[0]['argv'][1] ?? null)
        ->toBe('read-global')
        ->and($requests[1]['argv'][1] ?? null)
        ->toBe('write-site')
        ->and($sitePayload)
        ->toMatchArray(['domain' => 'docs.test'])
        ->and($requests[2]['argv'][1] ?? null)
        ->toBe('reload');
});

it('creates a static app proxy route with file_server', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'tld' => 'test',
            'wireguard_address' => '10.47.0.32',
        ]);
    $app = App::factory()
        ->for($node, 'node')
        ->static()
        ->create([
            'name' => 'marketing',
            'document_root' => 'public',
        ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.32:9477/v1/commands' => Http::sequence()
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

    app(EnsureAppProxyRoute::class)->handle($app);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $requests = ensure_app_proxy_route_agent_requests('10.47.0.32');
    $sitePayload = json_decode((string) ($requests[1]['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);
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
        ->toContain("root * {$app->path}/public")
        ->and($caddySite)
        ->not->toContain('php_fastcgi')->and($caddySite)
        ->not->toContain('reverse_proxy');
    expect($requests)
        ->toHaveCount(3)
        ->and($requests[1]['argv'][0] ?? null)
        ->toBe('internal:caddy-config')
        ->and($requests[1]['argv'][1] ?? null)
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
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'api',
        'document_root' => 'public',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
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

    app(EnsureAppProxyRoute::class)->handle($app);

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
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'happie-nmbp',
        'document_root' => 'public',
        'domain' => 'happie.nmbp',
        'runtime' => AppRuntimeKind::Php,
    ]);

    ProxyRoute::factory()->create([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'happie-nmbp.nmbp',
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(EnsureAppProxyRoute::class)->handle($app);

    expect(ProxyRoute::query()->where('app_id', $app->id)->pluck('domain')->all())
        ->toBe(['happie.nmbp']);
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
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}
