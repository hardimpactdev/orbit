<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\ProxyRouteEnactment;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ProxyRouteFixer', function (): void {
    beforeEach(function (): void {});

    afterEach(function (): void {});

    it('restores a deleted expected agent tool route row and its Caddy artifact', function (): void {
        $node = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->restoreAgentToolRoute($node, new DriftEntry(
            family: 'proxy',
            key: 'proxy.agent_tool_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing agent tool route',
            detail: ['tool' => 'openclaw', 'domain' => 'openclaw.agent'],
        ));
        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->firstOrFail();

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'agent-1',
                'key' => 'proxy.agent_tool_route_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'route' => 'openclaw.agent',
                    'tool' => 'openclaw',
                ],
            ])
            ->and($route->owner_type)
            ->toBe('tool')
            ->and($route->kind)
            ->toBe('proxy')
            ->and($route->config)
            ->toMatchArray([
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
                'upstream' => 'http://host.docker.internal:8080',
                'owner_name' => 'openclaw',
            ])
            ->and($route->source_hash)
            ->toBe(new ProxyRouteRenderer()->sourceHash($route))
            ->and(proxy_fixer_scripts_contain(shell: $shell, needle: "internal:caddy-config 'write-site'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain(shell: $shell, needle: "internal:caddy-config 'reload'"))
            ->toBeTrue();
    });

    it('restores a mismatched same-owner agent tool route to canonical intent', function (): void {
        $node = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat(string: 'a', times: 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'openclaw',
            ],
        ]);
        new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->restoreAgentToolRoute($node, new DriftEntry(
            family: 'proxy',
            key: 'proxy.agent_tool_route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatched agent tool route',
            detail: ['tool' => 'openclaw', 'domain' => 'openclaw.agent'],
        ));
        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->firstOrFail();

        expect($action['status'])
            ->toBe('completed')
            ->and($route->config['upstream'])
            ->toBe('http://host.docker.internal:8080')
            ->and($route->source_hash)
            ->toBe(new ProxyRouteRenderer()->sourceHash($route));
    });

    it('re-applies missing custom proxy routes from gateway intent', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $renderer = new ProxyRouteRenderer;

        $action = new ProxyRouteFixer(
            $renderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/vite.docs.test.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.route_missing',
                'status' => 'completed',
            ])
            ->and(proxy_fixer_payload_paths($shell))
            ->toContain('/etc/orbit/certs/vite.docs.test.crt', '/etc/orbit/certs/vite.docs.test.key')
            ->and($siteScript)
            ->toContain("internal:caddy-config 'write-site'")
            ->and($caddySite)
            ->toContain('reverse_proxy http://host.docker.internal:5173')
            ->and($caddySite)
            ->not->toContain('127.0.0.1')->and(proxy_fixer_scripts_contain(
                shell: $shell,
                needle: "internal:caddy-config 'reload'",
            ))->toBeTrue()->and($siteScript)
            ->not->toContain("docker restart 'orbit-caddy'")->and($siteScript)
            ->not->toContain('sudo systemctl reload caddy')->and($route->refresh()->source_hash)->toBe(hash(
                'sha256',
                $caddySite,
            ))->and($route->refresh()->source_hash)->toBe($renderer->sourceHash($route));
    });

    it('repairs proxy routes without explicit transitional SSH fallback', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));

        expect($action['status'])
            ->toBe('completed')
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'write-site'"))
            ->toBeTrue()
            ->and($route->refresh()->source_hash)
            ->not->toBe(str_repeat('0', 64));
    });

    it('repairs Orbit-managed TLS before restoring the metrics router route', function (): void {
        $router = Node::factory()
            ->router()
            ->create([
                'name' => 'gateway',
                'wireguard_address' => '10.6.0.2',
            ]);
        NodeTool::factory()->create([
            'node_id' => $router->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => OrbitCaddyContainer::forPrivateNode('10.6.0.2')->spec()],
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'metrics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'owner_name' => 'grafana',
                'protocol' => 'http',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://gateway.metrics.orbit:3000',
                ],
                'upstreams' => [
                    ['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000],
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $renderer = new ProxyRouteRenderer;

        $action = new ProxyRouteFixer(
            $renderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/metrics.orbit.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'gateway',
                'key' => 'proxy.route_missing',
                'status' => 'completed',
            ])
            ->and($shell->nodes[0]->is($router))
            ->toBeTrue()
            ->and(proxy_fixer_payload_paths($shell))
            ->toContain('/etc/orbit/certs/metrics.orbit.crt', '/etc/orbit/certs/metrics.orbit.key')
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/metrics.orbit.caddy')
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'reload'"))
            ->toBeTrue()
            ->and($caddySite)
            ->toContain('tls /etc/orbit/certs/metrics.orbit.crt /etc/orbit/certs/metrics.orbit.key')
            ->and($caddySite)
            ->toContain('reverse_proxy http://gateway.metrics.orbit:3000')
            ->and($route->refresh()->source_hash)
            ->toBe(hash('sha256', $caddySite))
            ->and($route->refresh()->source_hash)
            ->toBe($renderer->sourceHash($route));
    });

    it('does not issue Orbit TLS before restoring ACME-managed routes', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'www.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'code' => 301,
                'tls' => ['managed_by' => 'acme'],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/www.docs.test.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action['status'])
            ->toBe('completed')
            ->and($shell->scripts)
            ->toHaveCount(2)
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/www.docs.test.caddy')
            ->and($siteScript)
            ->not
            ->toContain('/etc/orbit/certs/www.docs.test.crt')
            ->and($caddySite)
            ->toContain("tls {\n        issuer acme\n    }")
            ->and($caddySite)
            ->toContain('redir https://docs.test{uri} 301');
    });

    it('re-applies ingress routes and names the public side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $router->id, 'role' => 'router', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'source_hash' => str_repeat('c', 64),
                ],
                'router_backend_pool' => [[
                    'node_id' => $backend->id,
                    'node' => 'web-1',
                    'url' => 'http://10.6.0.21:80',
                ]],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.public_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/docs.test.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action['summary'])
            ->toBe('Re-applied public proxy route docs.test from gateway intent.')
            ->and($action['node'])
            ->toBe('edge-1')
            ->and($shell->nodes[0]->is($edge))
            ->toBeTrue()
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)
            ->toContain('reverse_proxy http://10.6.0.2:80')
            ->and($route->refresh()->source_hash)
            ->toBe(hash('sha256', $caddySite));
    });

    it('re-applies router routes and names the router side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $router->id, 'role' => 'router', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'source_hash' => str_repeat('c', 64),
                ],
                'router_backend_pool' => [[
                    'node_id' => $backend->id,
                    'node' => 'web-1',
                    'url' => 'http://10.6.0.21:80',
                ]],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.router_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/docs.test.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action['summary'])
            ->toBe('Re-applied private router route docs.test from gateway intent.')
            ->and($action['node'])
            ->toBe('gateway-1')
            ->and($shell->nodes[0]->is($router))
            ->toBeTrue()
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)
            ->not
            ->toContain('bind 10.6.0.2')
            ->and($caddySite)
            ->toContain('reverse_proxy http://10.6.0.21:80')
            ->and($route->refresh()->config['router_artifact']['source_hash'])
            ->toBe(hash('sha256', $caddySite));
    });

    it('retries the complete app route enactment before clearing partial state', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'enactment' => [
                    'status' => 'partial',
                    'planned_operations' => [],
                    'completed_operations' => [],
                    'failure' => [
                        'layer' => 'router',
                        'node' => 'gateway-1',
                        'operation' => 'caddy.router.install',
                    ],
                ],
            ],
        ]);
        $reenacted = [];

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
            appRouteEnactor: function (App $target) use ($route, &$reenacted): void {
                $reenacted[] = $target->name;
                $config = is_array($route->config) ? $route->config : [];
                $route->forceFill(['config' => ProxyRouteEnactment::converged($config)])->save();
            },
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.enactment_incomplete',
            kind: DriftKind::Divergent,
            summary: 'partial',
        ));

        expect($reenacted)
            ->toBe(['docs'])
            ->and($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.enactment_incomplete',
                'status' => 'completed',
            ])
            ->and(ProxyRouteEnactment::status($route->refresh()->config))
            ->toBe('converged');
    });

    it('installs the gateway CA trust pool and reloads the managed caddy container for websocket routes', function (): void {
        $router = Node::factory()
            ->router()
            ->managed()
            ->create([
                'name' => 'gateway-1',
                'wireguard_address' => '10.6.0.2',
            ]);
        NodeTool::factory()->create([
            'node_id' => $router->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => ['name' => 'orbit-e2e-gateway-orbit-caddy']],
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'protocol' => 'websocket',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'app-dev-1',
                        'url' => 'https://10.6.0.44:8080',
                    ],
                ],
                'router_backend_tls' => [
                    'trusted_by_gateway_ca' => true,
                    'ca_path' => '/etc/orbit/ca/root.crt',
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                    'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                    'key_path' => '/etc/orbit/certs/websocket.orbit.key',
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        app()->instance(RemoteShell::class, $shell);
        Http::preventStrayRequests();
        Http::fake([
            'http://10.6.0.2:9477/v1/commands' => Http::sequence()
                ->push(proxy_fixer_agent_response('managed-file.probe', [
                    'exists' => false,
                    'hash' => null,
                    'mode' => null,
                ]))
                ->push(proxy_fixer_agent_response('managed-file.write', [
                    'path' => '/etc/orbit/ca/root.crt',
                    'hash' => hash(algo: 'sha256', data: 'fake-root-ca'),
                    'mode' => '0644',
                ])),
        ]);

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $managedFilePayload = json_decode(
            (string) ($shell->options[1]['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/websocket.orbit.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action['status'])
            ->toBe('completed')
            ->and($shell->scripts[0])
            ->toContain("internal:managed-file 'probe'")
            ->and($shell->scripts[1])
            ->toContain("internal:managed-file 'write'")
            ->and($managedFilePayload)
            ->toMatchArray([
                'path' => '/etc/orbit/ca/root.crt',
                'content' => 'fake-root-ca',
                'mode' => '0644',
                'directory_mode' => '0755',
            ])
            ->and(proxy_fixer_payload_paths($shell))
            ->toContain('/etc/orbit/certs/websocket.orbit.crt', '/etc/orbit/certs/websocket.orbit.key')
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/websocket.orbit.caddy')
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'reload'"))
            ->toBeTrue()
            ->and($caddySite)
            ->toContain('tls /etc/orbit/certs/websocket.orbit.crt /etc/orbit/certs/websocket.orbit.key')
            ->and($caddySite)
            ->toContain('reverse_proxy https://10.6.0.44:8080')
            ->and($caddySite)
            ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
            ->and($route->refresh()->source_hash)
            ->toBe(hash('sha256', $caddySite));
    });

    it('re-applies private backend artifacts and names the backend side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_backend_pool' => [[
                    'node_id' => $backend->id,
                    'node' => 'web-1',
                    'url' => 'http://10.6.0.21:80',
                ]],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'runtime_upstream' => 'http://orbit-app-docs:8080',
                    'php_socket' => null,
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
            detail: ['backend_node_id' => $backend->id],
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/docs.test.backend.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action['summary'])
            ->toBe('Re-applied private backend route docs.test on web-1 from gateway intent.')
            ->and($action['node'])
            ->toBe('web-1')
            ->and($shell->nodes[0]->is($backend))
            ->toBeTrue()
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/docs.test.backend.caddy')
            ->and($caddySite)
            ->not->toContain('bind 10.6.0.21')->and($caddySite)->toContain(
                'reverse_proxy http://orbit-app-docs:8080',
            )->and($caddySite)
            ->not->toContain('php_fastcgi');
    });

    it('does not repair backend routes without an explicit matching backend node id', function (array $detail): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        $otherBackend = Node::factory()->create(['name' => 'web-2']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $otherBackend->id,
            'role' => 'app-prod',
            'status' => 'active',
        ]);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_backend_pool' => [[
                    'node_id' => $backend->id,
                    'node' => 'web-1',
                    'url' => 'http://10.6.0.21:80',
                ]],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
            detail: $detail,
        ));

        expect($action)
            ->toBeNull()
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->nodes)
            ->toBe([]);
    })->with([
        'missing backend node id' => [[]],
        'invalid backend node id' => [['backend_node_id' => 'web-1']],
        'nonmatching backend node id' => [['backend_node_id' => 999_999]],
    ]);

    it('repairs Orbit-managed TLS drift for custom proxy routes', function (string $key, DriftKind $kind): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: $key,
            kind: $kind,
            summary: 'tls drift',
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => $key,
                'status' => 'completed',
            ])
            ->and(proxy_fixer_payload_paths($shell))
            ->toContain('/etc/orbit/certs/vite.docs.test.crt', '/etc/orbit/certs/vite.docs.test.key')
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:managed-file 'write'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'reload'"))
            ->toBeTrue()
            ->and($shell->scripts[0])
            ->not->toContain('systemctl show caddy')->and($shell->scripts[0])
            ->not->toContain('orbit_caddy_group')->and($shell->scripts[0])
            ->not->toContain('getent group caddy')->and($shell->scripts[0])
            ->not->toContain('chgrp')->and($shell->scripts[0])
            ->not->toContain("docker restart 'orbit-caddy'")->and($shell->scripts[0])
            ->not->toContain('sudo systemctl reload caddy');
    })->with([
        'missing material' => ['proxy.tls_missing', DriftKind::Missing],
        'mismatched validity' => ['proxy.tls_mismatch', DriftKind::Divergent],
    ]);

    it('repairs custom proxy TLS material through managed caddy host mount sources', function (): void {
        $node = createTestAppHostNode([
            'name' => 'nmbp',
            'platform' => 'macos_15-4',
            'user' => 'nckrtl',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => [
                'container' => [
                    ...OrbitCaddyContainer::default()->spec(),
                    'mounts' => [
                        [
                            'source' => '/Users/nckrtl/.config/orbit',
                            'target' => '/etc/orbit',
                            'read_only' => true,
                        ],
                    ],
                ],
            ],
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'paseo.nmbp',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:6767'],
                'upstream' => 'http://host.docker.internal:6767',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.tls_missing',
            kind: DriftKind::Missing,
            summary: 'tls missing',
        ));

        expect($action['status'])
            ->toBe('completed')
            ->and(proxy_fixer_payload_paths($shell))
            ->toContain(
                '/Users/nckrtl/.config/orbit/certs/paseo.nmbp.crt',
                '/Users/nckrtl/.config/orbit/certs/paseo.nmbp.key',
            )
            ->not
            ->toContain(
                '/etc/orbit/certs/paseo.nmbp.crt',
                '/etc/orbit/certs/paseo.nmbp.key',
            )
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:managed-file 'write'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'reload'"))
            ->toBeTrue();
    });

    it('re-applies app proxy routes from gateway intent', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'runtime_upstream' => 'http://orbit-app-docs:8080',
                'php_socket' => null,
                'tls' => 'internal',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/docs.test.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.route_mismatch',
                'status' => 'completed',
            ])
            ->and($siteScript)
            ->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)
            ->toContain('tls /etc/orbit/certs/docs.test.crt /etc/orbit/certs/docs.test.key')
            ->and($caddySite)
            ->toContain('reverse_proxy http://orbit-app-docs:8080')
            ->and($caddySite)
            ->not->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')->and($caddySite)
            ->not->toContain('php_fastcgi')->and($certificates->hosts)->toBe([
                'docs.test',
            ])->and($route->refresh()->source_hash)->toBe(hash(
                'sha256',
                $caddySite,
            ))->and($route->refresh()->source_hash)->toBe(new ProxyRouteRenderer()->sourceHash($route));
    });

    it('re-applies canonical app instance routes from concrete app instance intent', function (): void {
        $node = createTestAppHostNode(['name' => 'nmbp', 'user' => 'nckrtl', 'tld' => 'nmbp']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'happie',
            'domain' => 'happie.test',
            'path' => '/Users/nckrtl/apps/happie',
            'document_root' => 'public',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);
        AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: 'nmbp',
                path: '/Users/nckrtl/apps/happie',
                document_root: 'public',
                domain: 'happie.nmbp',
            ),
        ]);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'happie.nmbp',
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('0', 64),
                'config' => [
                    'document_root' => '/Users/nckrtl/apps/happie/public',
                    'runtime_upstream' => 'https://orbit-app-happie:8443',
                    'runtime_upstream_tls' => [
                        'trusted_by_gateway_ca' => true,
                        'ca_path' => '/etc/orbit/ca/root.crt',
                        'server_name' => 'happie.test',
                    ],
                    'php_socket' => null,
                    'tls' => [
                        'cert_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt',
                        'key_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.key',
                    ],
                ],
            ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/happie.nmbp.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);
        $config = $route->refresh()->config;

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'nmbp',
                'key' => 'proxy.route_mismatch',
                'status' => 'completed',
            ])
            ->and($caddySite)
            ->toContain('reverse_proxy https://orbit-app-happie-nmbp:8443')
            ->toContain('tls_server_name happie.nmbp')
            ->not->toContain('reverse_proxy https://orbit-app-happie:8443')
            ->not->toContain('tls_server_name happie.test')->and($config['target'])->toBe([
                'type' => 'app_instance',
                'value' => 'happie.nmbp',
            ])->and($config['app_instance'])->toMatchArray([
                'name' => 'nmbp',
                'selector' => 'happie.nmbp',
                'domain' => 'happie.nmbp',
            ])->and($config['runtime_upstream'])->toBe(
                'https://orbit-app-happie-nmbp:8443',
            )->and($route->source_hash)->toBe(hash('sha256', $caddySite));
    });

    it('repairs app route TLS through the site certificate installer', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.tls_missing',
            kind: DriftKind::Missing,
            summary: 'tls missing',
        ));
        $siteScript = proxyFixerSiteScript($shell, path: '/etc/caddy/sites/docs.test.caddy');
        $caddySite = proxyFixerDecodedSite($siteScript);

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.tls_missing',
                'status' => 'completed',
            ])
            ->and($certificates->hosts)
            ->toBe(['docs.test'])
            ->and($caddySite)
            ->toContain(
                'tls /home/orbit/.config/orbit/certs/docs.test.crt /home/orbit/.config/orbit/certs/docs.test.key',
            )
            ->and($caddySite)
            ->not
            ->toContain('/etc/orbit/certs/docs.test.crt')
            ->and($route->refresh()->config['tls'])
            ->toMatchArray([
                'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
            ]);
    });

    it(
        'restores a legacy app route persisted with only php_socket by deriving the FrankenPHP runtime upstream from the app identity (instead of throwing)',
        function (): void {
            $node = createTestAppHostNode(['name' => 'app-1']);
            $app = App::factory()->for($node, 'node')->create(['name' => 'legacy-docs']);
            $route = ProxyRoute::factory()
                ->for($node, 'node')
                ->for($app, 'app')
                ->create([
                    'domain' => 'legacy-docs.test',
                    'owner_type' => 'app',
                    'kind' => 'app',
                    'source_hash' => str_repeat('0', 64),
                    'config' => [
                        'document_root' => '/home/orbit/apps/legacy-docs/public',
                        // origin/main legacy persisted config: only php_socket, no runtime_upstream
                        'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                        'tls' => [
                            'cert_path' => '/etc/orbit/certs/legacy-docs.test.crt',
                            'key_path' => '/etc/orbit/certs/legacy-docs.test.key',
                        ],
                    ],
                ]);

            $shell = new ProxyFixerRecordingRemoteShell;
            $action = new ProxyRouteFixer(
                new ProxyRouteRenderer,
                new ProxyFixerFakeCa,
                new SiteCertificateInstallerFake,
            )->fix($route, new DriftEntry(
                family: 'proxy',
                key: 'proxy.route_mismatch',
                kind: DriftKind::Divergent,
                summary: 'mismatch',
            ));

            $caddySite = proxyFixerDecodedSite(proxyFixerSiteScript(
                $shell,
                path: '/etc/caddy/sites/legacy-docs.test.caddy',
            ));

            expect($action['status'])
                ->toBe('completed')
                ->and($caddySite)
                ->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
                ->and($caddySite)
                ->not->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')->and($caddySite)
                ->not->toContain('php_fastcgi')->and($caddySite)
                ->not->toContain('file_server')->and($route->refresh()->config['runtime_upstream'])->toBe(
                    'http://orbit-app-legacy-docs:8080',
                )->and($route->refresh()->config['runtime_upstream_tls'] ?? null)->toBeNull()->and(
                    $route->refresh()->config['php_socket'],
                )->toBeNull();
        },
    );

    it('starts the orbit-caddy container on the serving node when proxy.caddy_container_down is reported', function (): void {
        $node = createTestAppHostNode([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.21',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => OrbitCaddyContainer::forPrivateNode('10.6.0.21')->spec()],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_down',
                kind: DriftKind::Divergent,
                summary: 'orbit-caddy is not running',
                detail: ['container' => 'orbit-caddy', 'node' => 'app-1'],
            ),
        );

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.caddy_container_down',
                'status' => 'completed',
            ])
            ->and($shell->nodes[0]->is($node))
            ->toBeTrue()
            ->and($shell->scripts[0])
            ->toContain('/run/php')
            ->and($shell->scripts[0])
            ->toContain('expected_hash=')
            ->and($shell->scripts[0])
            ->toContain('docker start')
            ->and($shell->scripts[0])
            ->not->toContain('systemctl')->and($shell->scripts[0])
            ->not->toContain('caddy.service');
    });

    it(
        'reconciles the orbit-caddy container on the serving node using the per-node managed spec when proxy.caddy_container_missing is reported',
        function (): void {
            $node = createTestAppHostNode(['name' => 'app-1', 'wireguard_address' => '10.6.0.21']);
            // Persist a role-specific spec on the NodeTool record so the fixer
            // recreates orbit-caddy with the per-node bindings (private node) and
            // not the generic default spec.
            $managedSpec = OrbitCaddyContainer::forPrivateNode('10.6.0.21')->spec();
            NodeTool::factory()->create([
                'node_id' => $node->id,
                'name' => 'caddy',
                'expected_state' => 'installed',
                'config' => ['container' => $managedSpec],
            ]);
            $shell = new ProxyFixerRecordingRemoteShell;

            $action = new ProxyRouteFixer(
                new ProxyRouteRenderer,
                new ProxyFixerFakeCa,
                new SiteCertificateInstallerFake,
            )->fixCaddyContainer(
                $node,
                new DriftEntry(
                    family: 'proxy',
                    key: 'proxy.caddy_container_missing',
                    kind: DriftKind::Missing,
                    summary: 'orbit-caddy is absent',
                    detail: ['container' => 'orbit-caddy', 'node' => 'app-1'],
                ),
            );

            expect($action)
                ->toMatchArray([
                    'family' => 'proxy',
                    'node' => 'app-1',
                    'key' => 'proxy.caddy_container_missing',
                    'status' => 'completed',
                ])
                ->and($shell->nodes[0]->is($node))
                ->toBeTrue()
                ->and($shell->scripts[0])
                ->toContain('orbit-caddy')
                ->and($shell->scripts[0])
                ->toContain('docker run')
                // Per-node spec includes WireGuard-bound port publish; the default
                // spec does not. This proves the fixer used the managed spec.
                ->and($shell->scripts[0])
                ->toContain('10.6.0.21:80:80')
                ->and($shell->scripts[0])
                ->not->toContain('systemctl');
        },
    );

    it('refuses to recreate orbit-caddy when the node has no managed caddy tool record', function (): void {
        $node = createTestAppHostNode(['name' => 'app-2']);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-caddy is absent',
                detail: ['container' => 'orbit-caddy', 'node' => 'app-2'],
            ),
        );

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-2',
                'key' => 'proxy.caddy_container_missing',
                'status' => 'refused',
            ])
            ->and($action['details']['reason'])
            ->toBe('no_managed_caddy_tool')
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->nodes)
            ->toBe([]);
    });

    it('reconciles stale global orbit-caddy config through the agent caddy config action', function (): void {
        $node = createTestAppHostNode(['name' => 'NMBP']);
        $shell = new ProxyFixerRecordingRemoteShell(<<<'CADDY'
            {
                local_certs
            }
            CADDY);

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fixGlobalConfig($node, new DriftEntry(
            family: 'proxy',
            key: 'proxy.global_config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'global config mismatch',
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'NMBP',
                'key' => 'proxy.global_config_mismatch',
                'status' => 'completed',
            ])
            ->and($shell->globalConfig)
            ->toContain('import /etc/caddy/sites/*.caddy')
            ->and($shell->globalConfig)
            ->toContain('(profiling_headers)')
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'read-global'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'write-global'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'reload'"))
            ->toBeTrue();
    });

    it('reloads the e2e caddy container scoped to the serving node', function (): void {
        $network = getenv('ORBIT_E2E_DOCKER_NETWORK');
        $nodeContainer = getenv('ORBIT_NODE_CONTAINER');
        putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123');
        putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run123-gateway');

        try {
            $node = createTestAppHostNode(['name' => 'app-prod-1', 'host' => 'prod']);
            $shell = new ProxyFixerRecordingRemoteShell("{\n    local_certs\n}\n");

            new ProxyRouteFixer(
                new ProxyRouteRenderer,
                new ProxyFixerFakeCa,
                new SiteCertificateInstallerFake,
            )->fixGlobalConfig($node, new DriftEntry(
                family: 'proxy',
                key: 'proxy.global_config_mismatch',
                kind: DriftKind::Divergent,
                summary: 'global config mismatch',
            ));

            $reloadPayload = collect($shell->payloads)
                ->first(fn (array $payload): bool => isset($payload['container']));

            expect($reloadPayload)
                ->toMatchArray(['container' => 'orbit-e2e-run123-prod-orbit-caddy']);
        } finally {
            putenv($network === false ? 'ORBIT_E2E_DOCKER_NETWORK' : "ORBIT_E2E_DOCKER_NETWORK={$network}");
            putenv($nodeContainer === false ? 'ORBIT_NODE_CONTAINER' : "ORBIT_NODE_CONTAINER={$nodeContainer}");
        }
    });

    it('removes extra proxy routes from both site files and legacy global caddy blocks', function (): void {
        $node = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        $shell = new ProxyFixerRecordingRemoteShell(<<<'CADDY'
            {
                local_certs
            }

            paper.nmbp {
                reverse_proxy http://127.0.0.1:29979
            }
            CADDY);

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->removeExtra($node, 'paper.nmbp');

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'NMBP',
                'key' => 'paper.nmbp',
                'status' => 'completed',
            ])
            ->and($shell->globalConfig)
            ->not
            ->toContain('paper.nmbp')
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'remove-site'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'write-global'"))
            ->toBeTrue();
    });

    it('uses the public-ingress spec when the node is an ingress role host', function (): void {
        $node = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.4']);
        NodeRoleAssignment::factory()->create(['node_id' => $node->id, 'role' => 'ingress', 'status' => 'active']);
        // Spec containing public-ingress port bindings (80/443/443 udp +
        // wireguard backend port). These do not appear in the default spec.
        $managedSpec = OrbitCaddyContainer::forPublicIngress('10.6.0.4')->spec();
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $managedSpec],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-caddy is absent',
                detail: ['container' => 'orbit-caddy', 'node' => 'edge-1'],
            ),
        );

        expect($action['status'])
            ->toBe('completed')
            ->and($shell->scripts[0])
            ->toContain('80:80')
            ->and($shell->scripts[0])
            ->toContain('443:443')
            ->and($shell->scripts[0])
            ->toContain(
                '10.6.0.4:'.OrbitCaddyContainer::PrivateBackendPort.':'.OrbitCaddyContainer::PrivateBackendPort,
            );
    });

    it(
        'restores a legacy private backend artifact persisted with only php_socket by deriving runtime_upstream from the app identity',
        function (): void {
            $edge = Node::factory()->create(['name' => 'edge-1']);
            $backend = Node::factory()->create(['name' => 'web-1']);
            NodeRoleAssignment::factory()->create([
                'node_id' => $backend->id,
                'role' => 'app-prod',
                'status' => 'active',
            ]);
            $app = App::factory()->for($backend, 'node')->create(['name' => 'legacy-docs']);
            $route = ProxyRoute::factory()
                ->for($edge, 'node')
                ->for($app, 'app')
                ->create([
                    'domain' => 'legacy-docs.test',
                    'owner_type' => 'app',
                    'kind' => 'app',
                    'source_hash' => str_repeat('0', 64),
                    'config' => [
                        'placement' => 'ingress',
                        'backend_artifacts' => [
                            [
                                'node_id' => $backend->id,
                                'bind' => '10.6.0.21',
                                'document_root' => '/home/orbit/apps/legacy-docs/public',
                                // legacy backend artifact: php_socket only, no runtime_upstream
                                'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                            ],
                        ],
                    ],
                ]);

            $shell = new ProxyFixerRecordingRemoteShell;
            $action = new ProxyRouteFixer(
                new ProxyRouteRenderer,
                new ProxyFixerFakeCa,
                new SiteCertificateInstallerFake,
            )->fix($route, new DriftEntry(
                family: 'proxy',
                key: 'proxy.backend_route_mismatch',
                kind: DriftKind::Divergent,
                summary: 'backend mismatch',
                detail: ['backend_node_id' => $backend->id],
            ));

            $caddySite = proxyFixerDecodedSite(proxyFixerSiteScript(
                shell: $shell,
                path: '/etc/caddy/sites/legacy-docs.test.backend.caddy',
            ));

            expect($action['status'])
                ->toBe('completed')
                ->and($caddySite)
                ->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
                ->and($caddySite)
                ->not->toContain('php_fastcgi');
        },
    );
});

final readonly class ProxyFixerFakeCa extends OrbitCaService
{
    #[\Override]
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }

    /** @return array{cert: string, key: string} */
    #[\Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-proxy-fixer-ca';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $cert = "{$dir}/{$host}.crt";
        $key = "{$dir}/{$host}.key";

        file_put_contents($cert, "fake-cert-for-{$host}");
        file_put_contents($key, "fake-key-for-{$host}");

        return ['cert' => $cert, 'key' => $key];
    }
}

function proxyFixerSiteScript(ProxyFixerRecordingRemoteShell $shell, string $path): string
{
    $script = collect($shell->scripts)
        ->first(
            fn (string $script, int $index): bool => (
                str_contains($script, $path) || proxy_fixer_script_writes_path($shell, $index, $path)
            ),
        );

    expect($script)->not->toBeNull("Expected a proxy fixer script writing {$path}.");

    return (string) $script;
}

function proxyFixerDecodedSite(string $script): string
{
    if (str_contains($script, "internal:caddy-config 'write-site'")) {
        $payload = proxy_fixer_payload_from_synthetic_script($script);

        return is_string($payload['content'] ?? null) ? $payload['content'] : '';
    }

    $decoded = base64_decode(str($script)->match("/printf %s\\s+'([^']+)'/")->toString(), strict: true);

    return is_string($decoded) ? $decoded : '';
}

/**
 * @return list<string>
 */
function proxy_fixer_payload_paths(ProxyFixerRecordingRemoteShell $shell): array
{
    $paths = [];

    foreach ($shell->payloads as $payload) {
        $path = $payload['path'] ?? null;

        if (is_string($path)) {
            $paths[] = $path;
        }
    }

    return $paths;
}

function proxy_fixer_scripts_contain(ProxyFixerRecordingRemoteShell $shell, string $needle): bool
{
    return collect($shell->scripts)
        ->contains(fn (string $script): bool => str_contains($script, $needle));
}

function proxy_fixer_script_writes_path(ProxyFixerRecordingRemoteShell $shell, int $index, string $path): bool
{
    $payload = $shell->payloads[$index] ?? [];
    $domain = $payload['domain'] ?? null;

    if (! is_string($domain)) {
        return false;
    }

    $suffix = ($payload['backend'] ?? null) === true ? '.backend' : '';

    return $path === "/etc/caddy/sites/{$domain}{$suffix}.caddy";
}

/**
 * @return array<string, mixed>
 */
function proxy_fixer_payload_from_synthetic_script(string $script): array
{
    $matches = [];

    if (preg_match('/# ORBIT_TEST_PAYLOAD (?P<payload>[A-Za-z0-9+\/=]+)/', $script, $matches) !== 1) {
        return [];
    }

    $decoded = base64_decode($matches['payload'], strict: true);

    if (! is_string($decoded)) {
        return [];
    }

    /** @var mixed $payload */
    $payload = json_decode($decoded, associative: true);

    if (! is_array($payload)) {
        return [];
    }

    $normalized = [];

    foreach ($payload as $key => $value) {
        if (! is_string($key)) {
            continue;
        }

        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function proxy_fixer_agent_response(string $operationId, array $data, int $exitCode = 0): array
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
function proxy_fixer_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}

final class ProxyFixerRecordingRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function __construct(
        public ?string $globalConfig = null,
    ) {
        app()->instance(RemoteShell::class, $this);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $payload = proxy_fixer_decode_input($options['input'] ?? null);
        $this->nodes[] = $node;
        $this->scripts[] = proxy_fixer_synthetic_script($script, $payload);
        $this->options[] = $options;
        $this->payloads[] = $payload;

        if (str_contains($script, "internal:managed-file 'probe'")) {
            return proxy_fixer_shell_success([
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ]);
        }

        if (str_contains($script, "internal:managed-file 'write'")) {
            return proxy_fixer_shell_success([
                'path' => '/etc/orbit/ca/root.crt',
                'hash' => hash('sha256', 'fake-root-ca'),
                'mode' => '0644',
            ]);
        }

        if (str_contains($script, "internal:caddy-config 'read-global'")) {
            return proxy_fixer_shell_success([
                'content' => $this->globalConfig ?? '',
            ]);
        }

        if (str_contains($script, "internal:caddy-config 'write-global'")) {
            $content = $payload['content'] ?? null;

            if (is_string($content)) {
                $this->globalConfig = $content;
            }

            return proxy_fixer_shell_success([
                'content' => $this->globalConfig ?? '',
            ]);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/**
 * @return array<string, mixed>
 */
function proxy_fixer_decode_input(mixed $input): array
{
    if (! is_string($input) || $input === '') {
        return [];
    }

    /** @var mixed $payload */
    $payload = json_decode($input, associative: true);

    if (! is_array($payload)) {
        return [];
    }

    $normalized = [];

    foreach ($payload as $key => $value) {
        if (! is_string($key)) {
            continue;
        }

        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * @param  array<string, mixed>  $payload
 */
function proxy_fixer_synthetic_script(string $script, array $payload): string
{
    if ($payload === []) {
        return $script;
    }

    $synthetic =
        $script
        ."\n# ORBIT_TEST_PAYLOAD "
        .base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))
        ."\n# ORBIT_TEST_JSON "
        .json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (str_contains($script, "internal:caddy-config 'write-site'")) {
        $domain = is_string($payload['domain'] ?? null) ? $payload['domain'] : 'unknown';
        $suffix = ($payload['backend'] ?? null) === true ? '.backend' : '';
        $synthetic .= "\n# /etc/caddy/sites/{$domain}{$suffix}.caddy";
    }

    if (str_contains($script, "internal:caddy-config 'apply-container'")) {
        $synthetic .= "\ndocker container inspect\ndocker run\ndocker start\nexpected_hash=\norbit.caddy.spec_hash";
    }

    if (str_contains($script, "internal:caddy-config 'reload'")) {
        $container = is_string($payload['container'] ?? null) ? $payload['container'] : 'orbit-caddy';
        $synthetic .= "\n".CaddyTool::reloadCommand($container);
    }

    return $synthetic;
}

/**
 * @param  array<string, mixed>  $data
 */
function proxy_fixer_shell_success(array $data): RemoteShellResult
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
