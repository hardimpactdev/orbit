<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\InstanceDriver;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Workspace;
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

/** @mago-expect lint:cyclomatic-complexity */
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
            'name' => 'hermes',
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
            detail: ['tool' => 'hermes', 'domain' => 'hermes.agent'],
        ));
        $route = ProxyRoute::query()->where('domain', 'hermes.agent')->firstOrFail();

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'agent-1',
                'key' => 'proxy.agent_tool_route_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'route' => 'hermes.agent',
                    'tool' => 'hermes',
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
                'owner_name' => 'hermes',
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
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat(string: 'a', times: 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'hermes',
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
            detail: ['tool' => 'hermes', 'domain' => 'hermes.agent'],
        ));
        $route = ProxyRoute::query()->where('domain', 'hermes.agent')->firstOrFail();

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

    it('does not repair malformed custom route ownership', function (array $attributes): void {
        $node = createTestAppHostNode(['name' => 'gateway-1']);
        $route = ProxyRoute::query()->create([
            'node_id' => $node->id,
            'domain' => 'custom.test',
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080'],
                'upstream' => 'http://127.0.0.1:8080',
            ],
            ...$attributes,
        ]);
        $original = $route->fresh()->getAttributes();

        $result = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Malformed custom route.',
        ));

        expect($result)
            ->toBeNull()
            ->and($route->fresh()->getAttributes())
            ->toBe($original);
    })->with([
        'stray app identity' => [fn (): array => ['app_id' => App::factory()->create()->id]],
        'stray workspace identity' => [fn (): array => ['workspace_id' => Workspace::factory()->create()->id]],
        'stray instance identity' => [fn (): array => ['instance_id' => Instance::factory()->create()->id]],
        'wrong kind' => [['kind' => 'app']],
        'wrong serving role' => [fn (): array => ['node_id' => Node::factory()->create()->id]],
        'wrong stable config' => [[
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'upstream' => 'http://127.0.0.1:8080',
            ],
        ]],
    ]);

    it('does not repair a complete router family route on a non-canonical router', function (): void {
        Node::factory()->router()->create(['name' => 'router-1']);
        $otherRouter = Node::factory()->router()->create(['name' => 'router-2']);
        $route = ProxyRoute::query()->create([
            'node_id' => $otherRouter->id,
            'domain' => 'metrics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => \App\Services\Metrics\MetricsServiceRoute::config(),
        ]);

        $result = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Wrong canonical router.',
        ));

        expect($result)->toBeNull();
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

    it('does not mutate or render a workspace route with conflicting ownership', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->for($app, 'app')->create(['name' => 'feature-a']);
        $otherInstance = Instance::factory()->for($app, 'app')->create(['name' => 'production']);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->for($workspace, 'workspace')
            ->create([
                'instance_id' => $workspace->instance_id,
                'domain' => 'feature-a.docs.test',
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'source_hash' => str_repeat('0', 64),
                'config' => [
                    'document_root' => '/home/orbit/apps/docs/.worktrees/feature-a/public',
                    'runtime_upstream' => 'http://orbit-ws-docs-feature-a',
                    'tls' => ['managed_by' => 'orbit'],
                ],
            ]);
        $route->forceFill(['instance_id' => $otherInstance->id])->save();
        $originalConfig = $route->config;
        $originalSourceHash = $route->source_hash;
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;
        $fixer = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        );

        expect(fn (): ?array => $fixer->fix($route->refresh(), new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        )))
            ->toThrow(
                RuntimeException::class,
                "Proxy route 'feature-a.docs.test' has conflicting workspace ownership.",
            );

        expect($route->refresh()->config)
            ->toBe($originalConfig)
            ->and($route->source_hash)
            ->toBe($originalSourceHash)
            ->and($certificates->hosts)
            ->toBe([])
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->payloads)
            ->toBe([]);
    });

    it('does not fix workspace owner and kind tuple mismatches', function (string $ownerType, string $kind): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->for($app, 'app')->create(['name' => 'feature-a']);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->for($workspace, 'workspace')
            ->create([
                'instance_id' => $workspace->instance_id,
                'domain' => 'feature-a.docs.test',
                'owner_type' => $ownerType,
                'kind' => $kind,
                'source_hash' => str_repeat('0', 64),
                'config' => [
                    'document_root' => '/home/orbit/apps/docs/.worktrees/feature-a/public',
                    'runtime_upstream' => 'http://orbit-ws-docs-feature-a',
                    'upstream' => 'http://orbit-ws-docs-feature-a',
                    'tls' => ['managed_by' => 'orbit'],
                ],
            ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;
        $fixer = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        );

        expect(fn (): ?array => $fixer->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        )))
            ->toThrow(
                RuntimeException::class,
                "Proxy route 'feature-a.docs.test' has conflicting workspace ownership.",
            );

        expect($certificates->hosts)
            ->toBe([])
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->payloads)
            ->toBe([]);
    })->with([
        'owner mismatch' => ['app', 'workspace'],
        'kind mismatch' => ['workspace', 'proxy'],
        'unsupported kind mismatch' => ['workspace', 'internal'],
    ]);

    it('does not repair invalid public binding ownership tuples', function (
        string $ownerType,
        string $invalidity,
    ): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => "{$ownerType}.docs.test",
            'owner_type' => $ownerType,
            'kind' => $invalidity === 'malformed kind' ? 'app' : 'proxy',
            'config' => ['upstream' => 'https://router.orbit'],
        ]);

        if ($invalidity === 'missing app') {
            $route->forceFill(['app_id' => null])->save();
        }

        if ($invalidity === 'missing instance') {
            $route->forceFill(['instance_id' => null])->save();
        }

        if ($invalidity === 'conflicting app') {
            $route->forceFill(['app_id' => App::factory()->create()->id])->save();
        }

        if ($invalidity === 'workspace identity') {
            $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
            $route->forceFill(['workspace_id' => $workspace->id])->save();
        }

        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;
        $fixer = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        );

        $result = $fixer->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));

        expect($result)
            ->toBeNull()
            ->and($certificates->hosts)
            ->toBe([])
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->payloads)
            ->toBe([]);
    })->with([
        'analytics missing app' => ['app-analytics', 'missing app'],
        'analytics missing instance' => ['app-analytics', 'missing instance'],
        'analytics conflicting app' => ['app-analytics', 'conflicting app'],
        'analytics malformed kind' => ['app-analytics', 'malformed kind'],
        'analytics workspace identity' => ['app-analytics', 'workspace identity'],
        'websocket missing app' => ['app-websocket', 'missing app'],
        'websocket missing instance' => ['app-websocket', 'missing instance'],
        'websocket conflicting app' => ['app-websocket', 'conflicting app'],
        'websocket malformed kind' => ['app-websocket', 'malformed kind'],
        'websocket workspace identity' => ['app-websocket', 'workspace identity'],
    ]);

    it('does not render or generically repair public family routes with invalid family tuples', function (
        string $ownerType,
        string $invalidity,
    ): void {
        $ingress = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $otherIngress = Node::factory()->ingress()->create(['name' => 'edge-2']);
        $router = Node::factory()
            ->router()
            ->create([
                'name' => 'router-1',
                'wireguard_address' => '10.6.0.2',
            ]);
        $backendRole = $ownerType === 'app-analytics' ? 'analytics' : 'websocket';
        $backend = Node::factory()
            ->withActiveRole($backendRole)
            ->create([
                'name' => "{$backendRole}-1",
                'wireguard_address' => '10.6.0.50',
            ]);
        $appNode = Node::factory()->create(['name' => 'app-1']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $appNode->id,
            'role' => 'app-prod',
            'status' => 'active',
            'settings' => ['ingress_node_id' => $ingress->id],
        ]);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create([
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        $protocol = $ownerType === 'app-analytics' ? 'analytics' : 'websocket';
        $serviceTarget = "https://{$protocol}.orbit";
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => "{$protocol}.docs.test",
            'owner_type' => $ownerType,
            'kind' => 'proxy',
            'config' => array_filter(
                [
                    'placement' => 'ingress',
                    'ingress_node_id' => $ingress->id,
                    'protocol' => $protocol,
                    'target' => ['type' => $protocol, 'value' => $serviceTarget],
                    'upstream' => $serviceTarget,
                    'tracking_paths' => $protocol === 'analytics' ? ['/js/*', '/api/event'] : null,
                    'router_upstream' => [
                        'node_id' => $router->id,
                        'node' => $router->name,
                        'url' => 'http://10.6.0.2:80',
                    ],
                    'router_backend_pool' => [[
                        'node_id' => $backend->id,
                        'node' => $backend->name,
                        'url' => $protocol === 'analytics' ? 'http://10.6.0.50:8000' : 'https://10.6.0.50:8080',
                    ]],
                    'router_backend_tls' => $protocol === 'websocket'
                        ? ['trusted_by_gateway_ca' => true, 'ca_path' => '/etc/orbit/ca/root.crt']
                        : null,
                    'router_artifact' => [
                        'node_id' => $router->id,
                        'node' => $router->name,
                        'source_hash' => str_repeat('a', 64),
                    ],
                    'tls' => [
                        'cert_path' => "/etc/orbit/certs/{$protocol}.docs.test.crt",
                        'key_path' => "/etc/orbit/certs/{$protocol}.docs.test.key",
                    ],
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
        ]);
        $config = $route->config;

        if ($invalidity === 'unsupported backend topology') {
            $extraBackend = Node::factory()
                ->withActiveRole($backendRole)
                ->create([
                    'name' => "{$backendRole}-2",
                    'wireguard_address' => '10.6.0.51',
                ]);
            $config['router_backend_pool'][] = [
                'node_id' => $extraBackend->id,
                'node' => $extraBackend->name,
                'url' => $protocol === 'analytics' ? 'http://10.6.0.51:8000' : 'https://10.6.0.51:8080',
            ];
        }

        if ($invalidity === 'inactive websocket backend') {
            $backend->forceFill(['status' => 'inactive'])->save();
        }

        match ($invalidity) {
            'wrong node' => $route->forceFill(['node_id' => $otherIngress->id])->save(),
            'wrong placement' => $config['placement'] = 'backend',
            'wrong ingress identity' => $config['ingress_node_id'] = $otherIngress->id,
            'wrong protocol' => $config['protocol'] = 'http',
            'wrong target' => $config['target']['value'] = 'https://unrelated.orbit',
            'wrong router identity' => $config['router_upstream']['node_id'] = $otherIngress->id,
            'wrong backend pool' => $config['router_backend_pool'][0]['node_id'] = $otherIngress->id,
            'wrong tls identity' => $config['tls']['cert_path'] = '/etc/orbit/certs/unrelated.crt',
            'wrong family config' => $protocol === 'analytics'
                ? ($config['tracking_paths'] = ['/admin/*'])
                : ($config['router_backend_tls']['ca_path'] = '/tmp/untrusted.crt'),
            'unsupported backend topology', 'inactive websocket backend' => null,
        };

        if ($invalidity !== 'wrong node') {
            $route->forceFill(['config' => $config])->save();
        }

        $rendererRejected = false;

        try {
            new ProxyRouteRenderer()->render($route->fresh());
        } catch (RuntimeException) {
            $rendererRejected = true;
        }

        $shell = new ProxyFixerRecordingRemoteShell;
        $result = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fix($route->fresh(), new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));

        expect($rendererRejected)
            ->toBeTrue()
            ->and($result)
            ->toBeNull()
            ->and($shell->scripts)
            ->toBe([]);
    })->with([
        'analytics wrong node' => ['app-analytics', 'wrong node'],
        'analytics wrong placement' => ['app-analytics', 'wrong placement'],
        'analytics wrong ingress identity' => ['app-analytics', 'wrong ingress identity'],
        'analytics wrong protocol' => ['app-analytics', 'wrong protocol'],
        'analytics wrong target' => ['app-analytics', 'wrong target'],
        'analytics wrong router identity' => ['app-analytics', 'wrong router identity'],
        'analytics wrong backend pool' => ['app-analytics', 'wrong backend pool'],
        'analytics wrong tls identity' => ['app-analytics', 'wrong tls identity'],
        'analytics wrong family config' => ['app-analytics', 'wrong family config'],
        'websocket wrong node' => ['app-websocket', 'wrong node'],
        'websocket wrong placement' => ['app-websocket', 'wrong placement'],
        'websocket wrong ingress identity' => ['app-websocket', 'wrong ingress identity'],
        'websocket wrong protocol' => ['app-websocket', 'wrong protocol'],
        'websocket wrong target' => ['app-websocket', 'wrong target'],
        'websocket wrong router identity' => ['app-websocket', 'wrong router identity'],
        'websocket wrong backend pool' => ['app-websocket', 'wrong backend pool'],
        'websocket wrong tls identity' => ['app-websocket', 'wrong tls identity'],
        'websocket wrong family config' => ['app-websocket', 'wrong family config'],
        'websocket unsupported backend topology' => ['app-websocket', 'unsupported backend topology'],
        'websocket inactive backend' => ['app-websocket', 'inactive websocket backend'],
    ]);

    it('does not fix a proxy route that persists the public instance projection label', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'invalid.docs.test',
            'owner_type' => 'instance',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;
        $fixer = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        );

        $result = $fixer->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));

        expect($result)
            ->toBeNull()
            ->and($route->refresh()->source_hash)
            ->toBe(str_repeat('0', 64))
            ->and($certificates->hosts)
            ->toBe([])
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->payloads)
            ->toBe([]);
    });

    it('does not repair invalid primary app ownership tuples', function (string $invalidity): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        if ($invalidity === 'missing app') {
            $route->forceFill(['app_id' => null])->save();
        }

        if ($invalidity === 'missing instance') {
            $route->forceFill(['instance_id' => null])->save();
        }

        if ($invalidity === 'conflicting app') {
            $route->forceFill(['app_id' => App::factory()->create()->id])->save();
        }

        if ($invalidity === 'wrong kind') {
            $route->forceFill(['kind' => 'proxy'])->save();
        }

        if ($invalidity === 'workspace identity') {
            $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
            $route->forceFill(['workspace_id' => $workspace->id])->save();
        }

        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;
        $fixer = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            $certificates,
        );

        $result = $fixer->fix($route->fresh(), new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));

        expect($result)
            ->toBeNull()
            ->and($certificates->hosts)
            ->toBe([])
            ->and($shell->scripts)
            ->toBe([])
            ->and($shell->payloads)
            ->toBe([]);
    })->with([
        'missing app',
        'missing instance',
        'conflicting app',
        'wrong kind',
        'workspace identity',
    ]);

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
            'config' => \App\Services\Metrics\MetricsServiceRoute::config(),
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
            ->toContain('reverse_proxy http://host.docker.internal:3000')
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
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app, 'app')->create();
        $route = ProxyRoute::factory()
            ->forApp($instance, $app)
            ->create([
                'node_id' => $edge->id,
                'app_id' => $app->id,
                'domain' => 'docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('0', 64),
                'config' => [
                    'placement' => 'ingress',
                    'router_upstream' => [
                        'node_id' => $router->id,
                        'node' => 'gateway-1',
                        'url' => 'http://10.6.0.2:80',
                    ],
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
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app, 'app')->create();
        $route = ProxyRoute::factory()
            ->forApp($instance, $app)
            ->create([
                'node_id' => $edge->id,
                'app_id' => $app->id,
                'domain' => 'docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'placement' => 'ingress',
                    'router_upstream' => [
                        'node_id' => $router->id,
                        'node' => 'gateway-1',
                        'url' => 'http://10.6.0.2:80',
                    ],
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
        $defaultNode = createTestAppHostNode(['name' => 'app-1', 'tld' => 'test']);
        $nmbpNode = createTestAppHostNode(['name' => 'nmbp', 'tld' => 'nmbp']);
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $defaultNode->id,
                node: $defaultNode->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.test',
            ),
        ]);
        $nmbp = Instance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $nmbpNode->id,
                node: $nmbpNode->name,
                path: '/Users/nckrtl/apps/docs',
                document_root: 'public',
                domain: 'docs.nmbp',
            ),
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $nmbpNode->id,
            'app_id' => $app->id,
            'instance_id' => $nmbp->id,
            'domain' => 'docs.nmbp',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'instance' => [
                    'id' => $nmbp->id,
                    'name' => 'nmbp',
                    'selector' => 'docs.nmbp',
                    'domain' => 'docs.nmbp',
                    'node' => 'nmbp',
                    'node_id' => $nmbpNode->id,
                ],
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
            appRouteEnactor: function (App $target, ?Instance $instance) use ($route, &$reenacted): void {
                $config = $instance?->driver_config;
                $reenacted[] = [
                    'app' => $target->name,
                    'domain' => $config instanceof OrbitInstanceDriverConfigData ? $config->domain : null,
                    'instance_id' => $instance?->id,
                ];
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
            ->toBe([[
                'app' => 'docs',
                'domain' => 'docs.nmbp',
                'instance_id' => $nmbp->id,
            ]])
            ->and($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'nmbp',
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
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app, 'app')->create();
        $route = ProxyRoute::factory()
            ->forApp($instance, $app)
            ->create([
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
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app, 'app')->create();
        $route = ProxyRoute::factory()
            ->forApp($instance, $app)
            ->create([
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
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->for($app, 'app')->create();
        $route = ProxyRoute::factory()
            ->forApp($instance, $app)
            ->create([
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
            ->toContain('reverse_proxy http://orbit-app-docs-development:8080')
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
        $app = App::factory()->create([
            'name' => 'happie',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
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
                'instance_id' => $instance->id,
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
                'type' => 'instance',
                'value' => 'happie.nmbp',
            ])->and($config['instance'])->toMatchArray([
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
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->for($app, 'app')->create();
        $route = ProxyRoute::factory()
            ->forApp($instance, $app)
            ->create([
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
            $app = App::factory()->create(['name' => 'legacy-docs']);
            $instance = Instance::factory()->for($app, 'app')->create();
            $route = ProxyRoute::factory()
                ->for($node, 'node')
                ->forApp($instance, $app)
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
                ->toContain('reverse_proxy http://orbit-app-legacy-docs-development:8080')
                ->and($caddySite)
                ->not->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')->and($caddySite)
                ->not->toContain('php_fastcgi')->and($caddySite)
                ->not->toContain('file_server')->and($route->refresh()->config['runtime_upstream'])->toBe(
                    'http://orbit-app-legacy-docs-development:8080',
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
                'summary' => 'Restored orbit-caddy container on app-1.',
            ])
            ->and($shell->nodes[0]->is($node))
            ->toBeTrue()
            ->and($shell->scripts[0])
            ->toContain('/run/php')
            ->and($shell->scripts[0])
            ->toContain('expected_hash=')
            ->and($shell->scripts[0])
            ->toContain('apply-container')
            ->and($shell->scripts[0])
            ->not->toContain('systemctl')->and($shell->scripts[0])
            ->not->toContain('caddy.service');
    });

    it('recreates a detached orbit-caddy container from its managed per-node spec', function (): void {
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
                key: 'proxy.caddy_container_detached',
                kind: DriftKind::Divergent,
                summary: 'orbit-caddy lost its managed network',
                detail: ['container' => 'orbit-caddy', 'node' => 'app-1'],
            ),
        );

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.caddy_container_detached',
                'status' => 'completed',
            ])
            ->and($shell->scripts[0])
            ->toContain('internal:caddy-config')
            ->toContain('apply-container')
            ->toContain('10.6.0.21:80:80');
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

    it('rewrites the obsolete intermediate_lifetime 3599d global option without touching PEM paths', function (): void {
        $node = createTestAppHostNode([
            'name' => 'mini',
            'wireguard_address' => '10.6.0.30',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => OrbitCaddyContainer::forPrivateNode('10.6.0.30')->spec()],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell(<<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                    }
                }
            }

            custom.mini {
                respond ok
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
            summary: 'legacy intermediate_lifetime 3599d',
            detail: ['node' => 'mini'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'mini',
                'key' => 'proxy.global_config_mismatch',
                'status' => 'completed',
            ])
            ->and($shell->globalConfig)
            ->not->toContain('intermediate_lifetime 3599d')
            ->not->toContain('intermediate_lifetime')->toContain('custom.mini')->toContain(
                'local_certs',
            )->and(proxy_fixer_scripts_contain(
                $shell,
                needle: "internal:caddy-config 'write-global'",
            ))->toBeTrue()->and(implode("\n", $shell->scripts))
            ->not->toContain('root.crt')
            ->not->toContain('intermediate.crt')
            ->not->toContain('/var/lib/orbit/caddy/data/pki');
    });

    it('restores missing global config through apply-container when a managed caddy spec exists', function (): void {
        $node = createTestAppHostNode([
            'name' => 'mini',
            'wireguard_address' => '10.6.0.30',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => OrbitCaddyContainer::forPrivateNode('10.6.0.30')->spec()],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = new ProxyRouteFixer(
            new ProxyRouteRenderer,
            new ProxyFixerFakeCa,
            new SiteCertificateInstallerFake,
        )->fixGlobalConfig($node, new DriftEntry(
            family: 'proxy',
            key: 'proxy.global_config_missing',
            kind: DriftKind::Missing,
            summary: 'global config missing',
            detail: ['node' => 'mini'],
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'proxy',
                'node' => 'mini',
                'key' => 'proxy.global_config_missing',
                'status' => 'completed',
                'summary' => 'Restored host global orbit-caddy config and container on mini.',
            ])
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'apply-container'"))
            ->toBeTrue()
            ->and(proxy_fixer_scripts_contain($shell, needle: "internal:caddy-config 'reload'"))
            ->toBeFalse()
            ->and($shell->scripts[0])
            ->toContain('global_config');
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
        'restores a legacy private backend artifact persisted with only php_socket by deriving runtime_upstream from the instance identity',
        function (): void {
            $edge = Node::factory()->create(['name' => 'edge-1']);
            $backend = Node::factory()->create(['name' => 'web-1']);
            NodeRoleAssignment::factory()->create([
                'node_id' => $backend->id,
                'role' => 'app-prod',
                'status' => 'active',
            ]);
            $app = App::factory()->create(['name' => 'legacy-docs']);
            $instance = Instance::factory()->for($app, 'app')->create();
            $route = ProxyRoute::factory()
                ->for($edge, 'node')
                ->forApp($instance, $app)
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
                ->toContain('reverse_proxy http://orbit-app-legacy-docs-development:8080')
                ->and($caddySite)
                ->not->toContain('php_fastcgi');
        },
    );
});

final readonly class ProxyFixerFakeCa extends OrbitCaService
{
    #[Override]
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }

    /** @return array{cert: string, key: string} */
    #[Override]
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
