<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Exceptions\RemoteShellFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function proxyProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function proxyProbeWithRemoteShell(RemoteShell $shell): ProxyRouteProbe
{
    return new ProxyRouteProbe(
        scripts: new ToolScriptDispatcher(new ProxyProbeScriptExecutor($shell)),
    );
}

function createProxyProbeGatewayAssignmentNode(): Node
{
    $node = Node::factory()->create(['status' => 'active']);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

function assignProxyProbeRole(Node $node, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => [],
    ]);
}

describe('ProxyRouteProbe interface', function (): void {
    it('has key and label', function (): void {
        $probe = new ProxyRouteProbe;

        expect($probe->key())->toBe('proxy')->and($probe->label())->toBe('Proxy');
    });

    it('returns an empty snapshot when route context is not probeable', function (): void {
        $route = new ProxyRoute(['domain' => 'docs.test']);

        expect(
            new ProxyRouteProbe()
                ->introspect($route)
                ->isEmpty(),
        )->toBeTrue();
    });

    it('fails the probe when the executed tool script exits unsuccessfully', function (): void {
        $node = createTestAppHostNode(['name' => 'ingress-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hauzer.app',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://10.6.0.2:80'],
                'upstream' => 'http://10.6.0.2:80',
            ],
        ]);
        $executor = new class implements RunsInternalCommands {
            public function runInternal(
                Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: json_encode([
                        'success' => ['data' => [
                            'exit_code' => 2,
                            'stdout' => '',
                            'stderr' => 'sh: 2: set: Illegal option -o pipefail',
                            'duration_ms' => 4,
                        ]],
                    ], JSON_THROW_ON_ERROR),
                    stderr: '',
                    durationMs: 4,
                );
            }
        };
        $probe = new ProxyRouteProbe(scripts: new ToolScriptDispatcher($executor));

        expect(fn (): ProbeSnapshot => $probe->introspect($route))
            ->toThrow(
                RemoteShellFailed::class,
                'RemoteShell failed on ingress-1 (exit 2): sh: 2: set: Illegal option -o pipefail',
            );
    });
});

describe('proxy registry probe foundation', function (): void {
    it('passes complete custom proxy routes on active app nodes', function (): void {
        $node = createTestAppHostNode();
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

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('passes complete custom proxy routes on active gateway role assignments', function (): void {
        $node = createProxyProbeGatewayAssignmentNode();
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

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('passes websocket service routes on active router role assignments', function (): void {
        $node = Node::factory()->router()->create(['status' => 'active']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'websocket',
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'app-dev-1', 'url' => 'https://10.6.0.44:8080'],
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('passes observed websocket service route artifacts rendered with long lived upgrade settings', function (): void {
        $node = Node::factory()->router()->create(['status' => 'active']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'websocket',
                'router_upstream' => [
                    'node_id' => $node->id,
                    'node' => 'router-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'app-dev-1', 'url' => 'https://10.6.0.44:8080'],
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                ],
            ],
        ]);
        $route->forceFill(['source_hash' => new ProxyRouteRenderer()->sourceHash($route)])->save();

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            'websocket.orbit' => [
                'route_exists' => true,
                'route_hash' => $route->source_hash,
                'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                'key_path' => '/etc/orbit/certs/websocket.orbit.key',
                'cert_exists' => true,
                'key_exists' => true,
            ],
        ]));

        expect($drift)->toBe([]);
    });

    it('passes app websocket public routes on active ingress role assignments', function (): void {
        $edge = Node::factory()->ingress()->create(['status' => 'active']);
        $router = Node::factory()->router()->create(['status' => 'active', 'name' => 'router-1']);
        $appNode = Node::factory()->appProd()->create(['status' => 'active']);
        $app = App::factory()->create(['node_id' => $appNode->id]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'ws.docs.test',
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit'],
                'router_artifact' => [
                    'node_id' => $router->id,
                    'node' => 'router-1',
                    'source_hash' => str_repeat('a', 64),
                ],
                'router_backend_pool' => [
                    ['node_id' => $router->id, 'node' => 'router-1', 'url' => 'https://websocket.orbit'],
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('passes observed app websocket public and router artifacts rendered with long lived upgrade settings', function (): void {
        $edge = Node::factory()->ingress()->create(['status' => 'active']);
        $router = Node::factory()->router()->create(['status' => 'active', 'name' => 'router-1']);
        $appNode = Node::factory()->appProd()->create(['status' => 'active']);
        $app = App::factory()->create(['node_id' => $appNode->id]);
        $renderer = new ProxyRouteRenderer;
        $config = [
            'placement' => 'ingress',
            'protocol' => 'websocket',
            'target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit'],
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => 'router-1',
                'url' => 'http://10.6.0.2:80',
            ],
            'router_backend_pool' => [
                ['node_id' => $router->id, 'node' => 'router-1', 'url' => 'https://websocket.orbit'],
            ],
            'tls' => [
                'cert_path' => '/home/orbit/.config/orbit/certs/ws.docs.test.crt',
                'key_path' => '/home/orbit/.config/orbit/certs/ws.docs.test.key',
            ],
        ];
        $routerRoute = new ProxyRoute([
            'node_id' => $router->id,
            'app_id' => $app->id,
            'domain' => 'ws.docs.test',
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $config['router_artifact'] = [
            'node_id' => $router->id,
            'node' => 'router-1',
            'source_hash' => hash('sha256', $renderer->renderRouterRoute($routerRoute)),
        ];
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'ws.docs.test',
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $route->forceFill(['source_hash' => $renderer->sourceHash($route)])->save();

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            'ws.docs.test' => [
                'public' => [
                    'route_exists' => true,
                    'route_hash' => $route->source_hash,
                    'cert_path' => '/home/orbit/.config/orbit/certs/ws.docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/ws.docs.test.key',
                    'cert_exists' => true,
                    'key_exists' => true,
                ],
                'router' => [
                    'route_exists' => true,
                    'route_hash' => $config['router_artifact']['source_hash'],
                ],
                'backends' => [],
            ],
        ]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete route records', function (): void {
        $node = createTestAppHostNode();
        $id = DB::table('proxy_routes')->insertGetId([
            'node_id' => $node->id,
            'domain' => 'broken.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $route = ProxyRoute::findOrFail($id);
        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires app owners to resolve', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => null,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.owner_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('requires workspace owners to resolve', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => null,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.owner_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('requires active gateway or app serving nodes', function (callable $createNode): void {
        $node = $createNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'unassigned node' => [fn (): Node => Node::factory()->create(['status' => 'active'])],
        'inactive app node' => [fn (): Node => Node::factory()->appDev()->create(['status' => 'inactive'])],
    ]);

    it('allows custom redirect routes on active ingress nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'www.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'code' => 301,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.node_invalid'))->toBeNull();
    });

    it('detects custom route conflicts with app domains', function (): void {
        $node = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.test']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.domain_conflict')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.domain_conflict')?->detail)
            ->toMatchArray([
                'owner_type' => 'app',
                'owner_name' => 'docs',
            ]);
    });

    it('accepts resolved app and workspace owners', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id]);

        $appRoute = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $workspaceRoute = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        expect(new ProxyRouteProbe()->diff($appRoute, new ProbeSnapshot([])))
            ->toBe([])
            ->and(new ProxyRouteProbe()->diff($workspaceRoute, new ProbeSnapshot([])))
            ->toBe([]);
    });
});

describe('proxy backend and TLS reality', function (): void {
    it('introspects backend route and TLS material for the selected route', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);
        $shell = new ProxyProbeRecordingRemoteShell(
            "1\t"
            .str_repeat('a', 64)
            ."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n",
        );

        $snapshot = proxyProbeWithRemoteShell($shell)->introspect($route);

        expect($snapshot->get('vite.docs.test'))
            ->toMatchArray([
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_path' => '/etc/orbit/certs/vite.docs.test.crt',
                'key_path' => '/etc/orbit/certs/vite.docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ])
            ->and($shell->nodes[0]->is($node))
            ->toBeTrue()
            ->and($shell->scripts[0])
            ->toContain("ORBIT_PROXY_DOMAIN='vite.docs.test'")
            ->toContain('cert_pem=$(base64 < "$cert"');
    });

    it('introspects backend route artifacts through orbit-caddy container mounts', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'paseo.nmbp',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:6767'],
                'upstream' => 'http://host.docker.internal:6767',
            ],
        ]);
        $shell = new ProxyProbeRecordingRemoteShell("0\t\t\t\t0\t0\t\t\n");

        proxyProbeWithRemoteShell($shell)->introspect($route);

        expect($shell->scripts[0])
            ->toContain('docker exec orbit-caddy sh')
            ->toContain('/etc/caddy/sites/${domain}${suffix}.caddy');
    });

    it('probes HTTPS runtime upstreams with the route domain as SNI', function (): void {
        $node = createTestAppHostNode(['tld' => 'nmbp']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'happie-nmbp',
            'document_root' => 'public',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'happie.nmbp',
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('a', 64),
                'config' => [
                    'document_root' => '/Users/nckrtl/apps/happie/public',
                    'runtime_upstream' =>
                        'https://orbit-app-happie-nmbp:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort,
                    'runtime_upstream_tls' => [
                        'trusted_by_gateway_ca' => true,
                        'ca_path' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
                        'server_name' => 'happie.nmbp',
                    ],
                    'php_socket' => null,
                    'tls' => [
                        'cert_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt',
                        'key_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.key',
                    ],
                ],
            ]);
        $shell = new ProxyProbeRecordingRemoteShell(
            "1\t"
            .str_repeat('a', 64)
            ."\t/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt"
            ."\t/Users/nckrtl/.config/orbit/certs/happie.nmbp.key"
            ."\t1\t1\t1\t"
            .base64_encode('HTTP/2 200')
            ."\n",
        );

        $snapshot = proxyProbeWithRemoteShell($shell)->introspect($route);

        expect($snapshot->get('happie.nmbp'))
            ->toMatchArray([
                'route_exists' => true,
                'runtime_upstream' => 'https://orbit-app-happie-nmbp:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort,
                'runtime_upstream_reachable' => true,
                'runtime_probe_error' => 'HTTP/2 200',
            ])
            ->and($shell->scripts[0])
            ->toContain('--connect-to "$domain:$port:$host:$port"')
            ->toContain('"https://$domain:$port$path"')
            ->toContain(
                "ORBIT_PROXY_RUNTIME_UPSTREAM='https://orbit-app-happie-nmbp:"
                .AppDevelopmentInnerTlsPolicy::InternalTlsPort
                ."'",
            );
    });

    it('reports missing ingress artifacts alongside incomplete persisted enactment', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $backend = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        assignProxyProbeRole($edge, 'ingress');
        assignProxyProbeRole($router, 'router');
        assignProxyProbeRole($backend, 'app-prod');

        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'app_id' => App::factory()->create(['node_id' => $backend->id])->id,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
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

        $snapshot = new ProbeSnapshot([
            'docs.test' => [
                'public' => ['route_exists' => false],
                'router' => ['route_exists' => true, 'route_hash' => str_repeat('c', 64)],
                'backends' => [
                    $backend->id => ['route_exists' => true, 'route_hash' => str_repeat('b', 64)],
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.public_route_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(proxyProbeIssue($drift, 'proxy.backend_route_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.enactment_incomplete')?->detail)
            ->toMatchArray([
                'status' => 'partial',
                'failure' => [
                    'layer' => 'router',
                    'node' => 'gateway-1',
                    'operation' => 'caddy.router.install',
                ],
            ]);
    });

    it('reports incomplete persisted enactment after every production artifact matches', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $backend = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        assignProxyProbeRole($edge, 'ingress');
        assignProxyProbeRole($router, 'router');
        assignProxyProbeRole($backend, 'app-prod');

        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'app_id' => App::factory()->create(['node_id' => $backend->id])->id,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
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

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            'docs.test' => [
                'public' => ['route_exists' => true, 'route_hash' => str_repeat('a', 64)],
                'router' => ['route_exists' => true, 'route_hash' => str_repeat('c', 64)],
                'backends' => [
                    $backend->id => ['route_exists' => true, 'route_hash' => str_repeat('b', 64)],
                ],
            ],
        ]));

        expect($drift)
            ->toHaveCount(1)
            ->and(proxyProbeIssue($drift, 'proxy.enactment_incomplete')?->detail)
            ->toMatchArray([
                'status' => 'partial',
                'failure' => [
                    'layer' => 'router',
                    'node' => 'gateway-1',
                    'operation' => 'caddy.router.install',
                ],
            ]);
    });

    it('does not report managed private backend artifacts as extra node routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        $backend = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        assignProxyProbeRole($edge, 'ingress');
        assignProxyProbeRole($backend, 'app-prod');

        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'app_id' => App::factory()->create(['node_id' => $backend->id])->id,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffNode($backend, new ProbeSnapshot([
            'docs.test.backend' => ['route_exists' => true, 'route_hash' => str_repeat('b', 64)],
        ]));

        expect(proxyProbeIssue($drift, 'docs.test.backend'))->toBeNull();
    });

    it('detects mismatched router artifacts for ingress routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $backend = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        assignProxyProbeRole($edge, 'ingress');
        assignProxyProbeRole($router, 'router');
        assignProxyProbeRole($backend, 'app-prod');

        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'app_id' => App::factory()->create(['node_id' => $backend->id])->id,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
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

        $snapshot = new ProbeSnapshot([
            'docs.test' => [
                'public' => ['route_exists' => true, 'route_hash' => str_repeat('a', 64)],
                'router' => ['route_exists' => true, 'route_hash' => str_repeat('d', 64)],
                'backends' => [
                    $backend->id => ['route_exists' => true, 'route_hash' => str_repeat('b', 64)],
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.router_route_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.backend_route_mismatch'))
            ->toBeNull();
    });

    it('detects mismatched backend artifacts for ingress routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $backend = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        assignProxyProbeRole($edge, 'ingress');
        assignProxyProbeRole($router, 'router');
        assignProxyProbeRole($backend, 'app-prod');

        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'app_id' => App::factory()->create(['node_id' => $backend->id])->id,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
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

        $snapshot = new ProbeSnapshot([
            'docs.test' => [
                'public' => ['route_exists' => true, 'route_hash' => str_repeat('a', 64)],
                'router' => ['route_exists' => true, 'route_hash' => str_repeat('c', 64)],
                'backends' => [
                    $backend->id => ['route_exists' => true, 'route_hash' => str_repeat('c', 64)],
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.backend_route_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.backend_route_mismatch')?->detail)
            ->toMatchArray([
                'backend_node_id' => $backend->id,
                'expected_hash' => str_repeat('b', 64),
                'observed_hash' => str_repeat('c', 64),
            ]);
    });

    it('detects invalid backend artifact nodes for ingress routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $backend = Node::factory()->create(['name' => 'web-1', 'status' => 'inactive']);
        assignProxyProbeRole($edge, 'ingress');
        assignProxyProbeRole($router, 'router');
        assignProxyProbeRole($backend, 'app-prod');

        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'app_id' => App::factory()->create(['node_id' => $backend->id])->id,
            'config' => [
                'placement' => 'ingress',
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

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.backend_node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects missing backend route reality', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.route_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects backend route hash mismatch', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('b', 64),
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.route_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.route_mismatch')?->detail)
            ->toMatchArray([
                'expected_hash' => str_repeat('a', 64),
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('detects unreachable runtime upstreams behind otherwise expected app routes', function (): void {
        $node = createTestAppHostNode(['user' => 'orbit', 'tld' => 'test']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'document_root' => 'public',
            'runtime_config' => ['proxy_transport' => 'http'],
        ]);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'document_root' => '/home/orbit/apps/docs/public',
                    'runtime_upstream' => 'http://orbit-app-docs:8080',
                    'php_socket' => null,
                    'tls' => [
                        'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                        'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                    ],
                ],
            ]);
        $expectedHash = new ProxyRouteRenderer()->sourceHash($route);
        $route->forceFill(['source_hash' => $expectedHash])->save();

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            'docs.test' => [
                'route_exists' => true,
                'route_hash' => $expectedHash,
                'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
                'runtime_upstream' => 'http://orbit-app-docs:8080',
                'runtime_upstream_reachable' => false,
                'runtime_probe_error' => 'wget: bad address orbit-app-docs',
            ],
        ]));

        expect(proxyProbeIssue($drift, key: 'proxy.runtime_unreachable')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, key: 'proxy.runtime_unreachable')?->detail)
            ->toMatchArray([
                'runtime_upstream' => 'http://orbit-app-docs:8080',
                'probe_error' => 'wget: bad address orbit-app-docs',
            ]);
    });

    it('accepts default app-dev PHP route artifacts that use HTTP runtime upstream intent', function (): void {
        $node = createTestAppHostNode(['user' => 'orbit', 'tld' => 'test']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'document_root' => '/home/orbit/apps/docs/public',
                    'runtime_upstream' => 'http://orbit-app-docs:8080',
                    'php_socket' => null,
                    'tls' => [
                        'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                        'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                    ],
                ],
            ]);
        $expectedHash = new ProxyRouteRenderer()->sourceHash($route);
        $route->forceFill(['source_hash' => $expectedHash])->save();

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            'docs.test' => [
                'route_exists' => true,
                'route_hash' => $expectedHash,
                'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
                'runtime_upstream_reachable' => true,
            ],
        ]));

        $issue = proxyProbeIssue($drift, 'proxy.route_mismatch');

        expect(new ProxyRouteRenderer()->render($route))
            ->toContain('reverse_proxy http://orbit-app-docs:8080')
            ->and($issue)
            ->toBeNull();
    });

    it('reports route mismatch when a canonical app instance route still targets the bare app runtime', function (): void {
        $node = createTestAppHostNode(['name' => 'nmbp', 'user' => 'nckrtl', 'tld' => 'nmbp']);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'happie',
            'domain' => 'happie.test',
            'path' => '/Users/nckrtl/apps/happie',
            'document_root' => 'public',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'happie.nmbp',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'document_root' => '/Users/nckrtl/apps/happie/public',
                    'runtime_upstream' => 'https://orbit-app-happie:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort,
                    'runtime_upstream_tls' => [
                        'trusted_by_gateway_ca' => true,
                        'ca_path' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
                        'server_name' => 'happie.test',
                    ],
                    'php_socket' => null,
                    'tls' => [
                        'cert_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt',
                        'key_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.key',
                    ],
                ],
            ]);
        $renderer = new ProxyRouteRenderer;
        $staleCaddy = $renderer->render($route);
        $staleHash = hash('sha256', $staleCaddy);
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
        $route->forceFill(['source_hash' => $staleHash])->save();
        $route = $route->fresh(['app.instances', 'node']);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            'happie.nmbp' => [
                'route_exists' => true,
                'route_hash' => $staleHash,
                'cert_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.crt',
                'key_path' => '/Users/nckrtl/.config/orbit/certs/happie.nmbp.key',
                'cert_exists' => true,
                'key_exists' => true,
            ],
        ]));

        $issue = proxyProbeIssue($drift, 'proxy.route_mismatch');
        $expectedHash = $renderer->managedPhpRuntimeIntentSourceHash($route);
        $expectedCaddy = $renderer->renderManagedPhpRuntimeIntent($route);

        expect($staleCaddy)
            ->toContain('reverse_proxy https://orbit-app-happie:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort)
            ->toContain('tls_server_name happie.test')
            ->and($issue?->kind)
            ->toBe(DriftKind::Divergent)
            ->and($issue?->detail['expected_hash'] ?? null)
            ->toBe($expectedHash)
            ->and($issue?->detail['observed_hash'] ?? null)
            ->toBe($staleHash)
            ->and($expectedHash)
            ->not
            ->toBe($staleHash)
            ->and($expectedCaddy)
            ->toContain('reverse_proxy https://orbit-app-happie-nmbp:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort)
            ->toContain('tls_server_name happie.nmbp');
    });

    it(
        'reports proxy.route_mismatch when an app-dev route still carries stale inner-TLS upstream config but the app now opts into HTTP runtime proxy transport',
        function (): void {
            $node = createTestAppHostNode(['user' => 'nckrtl', 'tld' => 'test']);
            $app = App::factory()->for($node, 'node')->create([
                'name' => 'nckrtl',
                'runtime' => AppRuntimeKind::Php,
                'document_root' => 'public',
                'runtime_config' => ['proxy_transport' => 'http'],
            ]);
            $route = ProxyRoute::factory()
                ->for($node, 'node')
                ->for($app, 'app')
                ->create([
                    'domain' => 'nckrtl.test',
                    'owner_type' => 'app',
                    'kind' => 'app',
                    'config' => [
                        'document_root' => '/home/nckrtl/apps/nckrtl/public',
                        'runtime_upstream' => 'https://orbit-app-nckrtl:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort,
                        'runtime_upstream_tls' => [
                            'trusted_by_gateway_ca' => true,
                            'ca_path' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
                            'server_name' => 'nckrtl.test',
                        ],
                        'php_socket' => null,
                        'tls' => [
                            'cert_path' => '/home/nckrtl/.config/orbit/certs/nckrtl.test.crt',
                            'key_path' => '/home/nckrtl/.config/orbit/certs/nckrtl.test.key',
                        ],
                    ],
                ]);

            $renderer = new ProxyRouteRenderer;
            $staleInnerTlsCaddy = $renderer->render($route);
            $staleInnerTlsHash = hash('sha256', $staleInnerTlsCaddy);
            $route->forceFill(['source_hash' => $staleInnerTlsHash])->save();

            expect($staleInnerTlsCaddy)
                ->toContain('reverse_proxy https://orbit-app-nckrtl:'.AppDevelopmentInnerTlsPolicy::InternalTlsPort)
                ->and($staleInnerTlsCaddy)
                ->toContain('tls_trust_pool file '.AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath)
                ->and($route->config['runtime_upstream'] ?? null)
                ->toStartWith('https://');

            $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
                'nckrtl.test' => [
                    'route_exists' => true,
                    'route_hash' => $staleInnerTlsHash,
                    'cert_path' => '/home/nckrtl/.config/orbit/certs/nckrtl.test.crt',
                    'key_path' => '/home/nckrtl/.config/orbit/certs/nckrtl.test.key',
                    'cert_exists' => true,
                    'key_exists' => true,
                ],
            ]));

            $issue = proxyProbeIssue($drift, 'proxy.route_mismatch');
            $expectedHash = $renderer->managedPhpRuntimeIntentSourceHash($route);
            $expectedCaddy = $renderer->renderManagedPhpRuntimeIntent($route);

            expect($issue?->kind)
                ->toBe(DriftKind::Divergent)
                ->and($issue?->detail['expected_hash'] ?? null)
                ->toBe($expectedHash)
                ->and($issue?->detail['observed_hash'] ?? null)
                ->toBe($staleInnerTlsHash)
                ->and($expectedHash)
                ->not->toBe($staleInnerTlsHash)->and($expectedCaddy)->toContain(
                    'reverse_proxy http://orbit-app-nckrtl:8080',
                )->and($expectedCaddy)
                ->not->toContain('tls_trust_pool file '.AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath);
        },
    );

    it('detects missing Orbit-managed TLS material', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => true,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing TLS material for internally managed websocket routes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'https://10.6.0.14:8080'],
                'upstream' => 'https://10.6.0.14:8080',
                'tls' => [
                    'managed_by' => 'internal',
                    'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                    'key_path' => '/etc/orbit/certs/websocket.orbit.key',
                ],
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'websocket.orbit' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not require Orbit-managed TLS files for public ingress routes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'www.docs.test',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'url' => 'http://10.6.0.2:80',
                ],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/www.docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/www.docs.test.key',
                    'managed_by' => 'orbit',
                ],
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'www.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'public' => [
                    'route_exists' => true,
                    'route_hash' => str_repeat('a', 64),
                    'cert_exists' => false,
                    'key_exists' => false,
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.tls_mismatch'))
            ->toBeNull();
    });

    it('does not require Orbit-managed TLS files for routes marked as ACME managed', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'www.docs.test',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'tls' => ['managed_by' => 'acme'],
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'www.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.tls_mismatch'))
            ->toBeNull();
    });

    it('detects mismatched Orbit-managed TLS paths', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => true,
                'key_exists' => true,
                'cert_path' => '/tmp/wrong.crt',
                'key_path' => '/tmp/wrong.key',
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects Orbit-managed TLS leaves issued outside the expected validity window', function (int $validityDays): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'analytics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://10.6.0.15:8000'],
                'upstream' => 'http://10.6.0.15:8000',
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'analytics.orbit' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => true,
                'key_exists' => true,
                'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                'key_path' => '/etc/orbit/certs/analytics.orbit.key',
                'cert_validity_days' => $validityDays,
            ],
        ]);

        $issue = proxyProbeIssue(new ProxyRouteProbe()->diff($route, $snapshot), 'proxy.tls_mismatch');

        expect($issue?->kind)
            ->toBe(DriftKind::Divergent)
            ->and($issue?->detail['observed']['validity_days'] ?? null)
            ->toBe($validityDays);
    })->with([
        'short-lived leaf' => 90,
        'overlong leaf' => 3650,
    ]);

    it('accepts Orbit-managed TLS leaves in the expected validity window', function (int $validityDays): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'analytics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://10.6.0.15:8000'],
                'upstream' => 'http://10.6.0.15:8000',
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'analytics.orbit' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => true,
                'key_exists' => true,
                'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                'key_path' => '/etc/orbit/certs/analytics.orbit.key',
                'cert_validity_days' => $validityDays,
            ],
        ]);

        expect(proxyProbeIssue(new ProxyRouteProbe()->diff($route, $snapshot), 'proxy.tls_mismatch'))->toBeNull();
    })->with([
        '397-day leaf' => 397,
        'rounding-tolerant 396-day leaf' => 396,
    ]);

    it('detects Orbit-managed TLS leaves whose validity cannot be parsed', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'analytics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://10.6.0.15:8000'],
                'upstream' => 'http://10.6.0.15:8000',
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'analytics.orbit' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => true,
                'key_exists' => true,
                'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                'key_path' => '/etc/orbit/certs/analytics.orbit.key',
                'cert_validity_observed' => true,
                'cert_validity_days' => null,
            ],
        ]);

        expect(proxyProbeIssue(new ProxyRouteProbe()->diff($route, $snapshot), 'proxy.tls_mismatch')?->kind)
            ->toBe(DriftKind::Divergent);
    });

    it('skips TLS drift for externally managed routes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'upstream' => 'http://127.0.0.1:5173',
                'tls' => ['managed_by' => 'external'],
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.tls_mismatch'))
            ->toBeNull();
    });

    it('skips TLS drift for internal TLS app and workspace routes', function (string $ownerType, string $kind): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $ownerType === 'workspace' ? $workspace->id : null,
            'domain' => "{$kind}.docs.test",
            'owner_type' => $ownerType,
            'kind' => $kind,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                'tls' => 'internal',
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            $route->domain => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => false,
                'cert_path' => '',
                'key_path' => '',
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.tls_mismatch'))
            ->toBeNull();
    })->with([
        'app route' => ['app', 'app'],
        'workspace route' => ['workspace', 'workspace'],
    ]);
});

describe('proxy node-level introspection', function (): void {
    it('introspects all caddy sites on a node', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeRecordingRemoteShell(
            "vite.docs.test\t"
            .str_repeat('a', 64)
            ."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n"
            ."api.docs.test\t"
            .str_repeat('b', 64)
            ."\t/etc/orbit/certs/api.docs.test.crt\t/etc/orbit/certs/api.docs.test.key\t1\t1\n",
        );

        $snapshot = proxyProbeWithRemoteShell($shell)->introspectNode($node);

        expect($snapshot->keys())
            ->toHaveCount(2)
            ->and($snapshot->get('vite.docs.test'))
            ->toMatchArray([
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_path' => '/etc/orbit/certs/vite.docs.test.crt',
                'key_path' => '/etc/orbit/certs/vite.docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ])
            ->and($snapshot->get('api.docs.test'))
            ->toMatchArray([
                'route_exists' => true,
                'route_hash' => str_repeat('b', 64),
                'cert_path' => '/etc/orbit/certs/api.docs.test.crt',
                'key_path' => '/etc/orbit/certs/api.docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ]);
    });

    it('returns empty snapshot when no caddy sites exist', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeRecordingRemoteShell('');

        $snapshot = proxyProbeWithRemoteShell($shell)->introspectNode($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('ignores malformed lines in node scan output', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeRecordingRemoteShell(
            "vite.docs.test\t"
            .str_repeat('a', 64)
            ."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n"
            ."malformed-line-without-tabs\n"
            ."\n",
        );

        $snapshot = proxyProbeWithRemoteShell($shell)->introspectNode($node);

        expect($snapshot->keys())
            ->toHaveCount(1)
            ->and($snapshot->get('vite.docs.test'))
            ->not->toBeNull();
    });

    it('introspects the global orbit-caddy config on a node', function (): void {
        $node = createTestAppHostNode();
        $contents = "paper.nmbp {\n    reverse_proxy http://127.0.0.1:29979\n}\n";
        $shell = new ProxyProbeRecordingRemoteShell("1\t".base64_encode($contents)."\n");

        $snapshot = proxyProbeWithRemoteShell($shell)->introspectGlobalConfig($node);

        expect($snapshot->get('global_caddy_config'))
            ->toMatchArray([
                'exists' => true,
                'content' => $contents,
                'hash' => hash('sha256', $contents),
            ])
            ->and($shell->scripts[0])
            ->toContain('/etc/caddy/Caddyfile');
    });
});

describe('proxy node-level diff', function (): void {
    it('reports route_missing for db routes not on node', function (): void {
        $node = createTestAppHostNode();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $drift = new ProxyRouteProbe()->diffNode($node, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.route_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('reports route_extra for node routes not in db', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'extra.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('c', 64),
                'cert_path' => '/etc/orbit/certs/extra.test.crt',
                'key_path' => '/etc/orbit/certs/extra.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffNode($node, $snapshot);

        expect(proxyProbeIssue($drift, 'extra.test')?->kind)->toBe(DriftKind::Extra);
    });

    it('reports both missing and extra routes', function (): void {
        $node = createTestAppHostNode();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'db-only.test',
            'source_hash' => str_repeat('a', 64),
        ]);
        $snapshot = new ProbeSnapshot([
            'node-only.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('b', 64),
                'cert_path' => '',
                'key_path' => '',
                'cert_exists' => false,
                'key_exists' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffNode($node, $snapshot);

        expect(count($drift))
            ->toBe(2)
            ->and(proxyProbeIssue($drift, 'proxy.route_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(proxyProbeIssue($drift, 'node-only.test')?->kind)
            ->toBe(DriftKind::Extra);
    });

    it('reports global caddy config drift and stale node-tld domains embedded in the global file', function (): void {
        $node = createTestAppHostNode([
            'name' => 'NMBP',
            'tld' => 'nmbp',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'happie.nmbp',
        ]);
        $contents = <<<'CADDY'
            {
                local_certs
            }

            paper.nmbp {
                reverse_proxy http://127.0.0.1:29979
            }
            CADDY;

        $drift = new ProxyRouteProbe()->diffGlobalConfig($node, new ProbeSnapshot([
            'global_caddy_config' => [
                'exists' => true,
                'content' => $contents,
                'hash' => hash('sha256', $contents),
            ],
        ]));

        expect(proxyProbeIssue($drift, key: 'proxy.global_config_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, key: 'proxy.global_config_mismatch')?->detail)
            ->toMatchArray([
                'node' => 'NMBP',
                'expected_imports' => [
                    '/etc/caddy/orbit/*.caddy',
                    '/etc/caddy/sites/*.caddy',
                ],
            ])
            ->and(proxyProbeIssue($drift, key: 'paper.nmbp')?->kind)
            ->toBe(DriftKind::Extra)
            ->and(proxyProbeIssue($drift, key: 'paper.nmbp')?->detail)
            ->toMatchArray([
                'domain' => 'paper.nmbp',
                'source' => 'global_caddy_config',
            ]);
    });
});

describe('proxy adoption snapshot', function (): void {
    it('returns vhost bodies for adoption', function (): void {
        $node = createTestAppHostNode();
        $body = "vite.docs.test {\n    reverse_proxy localhost:8080\n}\n";
        $bodyB64 = base64_encode($body);
        $shell = new ProxyProbeRecordingRemoteShell(
            "vite.docs.test\t".str_repeat('a', 64)."\t{$bodyB64}\n",
        );

        $snapshot = proxyProbeWithRemoteShell($shell)->snapshotForAdopt($node);

        expect($snapshot->keys())
            ->toHaveCount(1)
            ->and($snapshot->get('vite.docs.test'))
            ->toMatchArray([
                'hash' => str_repeat('a', 64),
                'body' => $body,
            ]);
    });
});

describe('orbit-caddy container readiness', function (): void {
    it('inspects the orbit-caddy container on a serving node without probing host caddy.service', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeCaddyContainerShell('available', 'true', 'true');

        $snapshot = proxyProbeWithRemoteShell($shell)->introspectCaddyContainer($node);

        expect($snapshot->get('orbit-caddy'))
            ->toMatchArray([
                'runtime_status' => 'available',
                'container_exists' => true,
                'container_running' => true,
            ])
            ->and($shell->scripts[0])
            ->toContain('docker container inspect')
            ->and($shell->scripts[0])
            ->toContain('.State.Restarting')
            ->and($shell->scripts[0])
            ->toContain('orbit-caddy')
            ->and($shell->scripts[0])
            ->toContain('orbit-proxy-doctor:caddy-container-probe')
            ->and($shell->scripts[0])
            ->not->toContain('systemctl')->and($shell->scripts[0])
            ->not->toContain('caddy.service');
    });

    it('scopes the e2e caddy container name to the serving node', function (): void {
        $network = getenv('ORBIT_E2E_DOCKER_NETWORK');
        $nodeContainer = getenv('ORBIT_NODE_CONTAINER');
        putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123');
        putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run123-gateway');

        try {
            $node = createTestAppHostNode(['name' => 'app-prod-1', 'host' => 'prod']);
            $shell = new ProxyProbeCaddyContainerShell('available', 'true', 'true');
            $snapshot = proxyProbeWithRemoteShell($shell)->introspectCaddyContainer($node);

            expect($snapshot->get('orbit-e2e-run123-prod-orbit-caddy'))
                ->toMatchArray([
                    'runtime_status' => 'available',
                    'container_exists' => true,
                    'container_running' => true,
                ])
                ->and($shell->scripts[0])
                ->toContain('orbit-e2e-run123-prod-orbit-caddy');
        } finally {
            putenv($network === false ? 'ORBIT_E2E_DOCKER_NETWORK' : "ORBIT_E2E_DOCKER_NETWORK={$network}");
            putenv($nodeContainer === false ? 'ORBIT_NODE_CONTAINER' : "ORBIT_NODE_CONTAINER={$nodeContainer}");
        }
    });

    it('reports proxy.caddy_container_missing when the container is absent on the serving node', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'orbit-caddy' => [
                'runtime_status' => 'available',
                'container_exists' => false,
                'container_running' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffCaddyContainer($node, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.caddy_container_missing')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(proxyProbeIssue($drift, 'proxy.caddy_container_missing')?->detail)
            ->toMatchArray([
                'container' => 'orbit-caddy',
                'node' => $node->name,
            ]);
    });

    it('reports proxy.caddy_container_down when the container exists but is not running', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'orbit-caddy' => [
                'runtime_status' => 'available',
                'container_exists' => true,
                'container_running' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffCaddyContainer($node, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.caddy_container_down')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.caddy_container_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.caddy_container_down')?->detail)
            ->toMatchArray([
                'container' => 'orbit-caddy',
                'node' => $node->name,
            ]);
    });

    it('reports no orbit-caddy container drift when the container exists and is running', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'orbit-caddy' => [
                'runtime_status' => 'available',
                'container_exists' => true,
                'container_running' => true,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffCaddyContainer($node, $snapshot);

        expect($drift)->toBe([]);
    });

    it('reports proxy.docker_runtime_unavailable (not container_missing) when docker CLI is absent on the node', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'orbit-caddy' => [
                'runtime_status' => 'no_docker',
                'container_exists' => false,
                'container_running' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffCaddyContainer($node, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.docker_runtime_unavailable')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.docker_runtime_unavailable')?->detail)
            ->toMatchArray([
                'container' => 'orbit-caddy',
                'node' => $node->name,
                'runtime_status' => 'no_docker',
            ])
            ->and(proxyProbeIssue($drift, 'proxy.caddy_container_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.caddy_container_down'))
            ->toBeNull();
    });

    it('reports proxy.docker_runtime_unavailable (not container_missing) when the docker daemon is unreachable on the node', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'orbit-caddy' => [
                'runtime_status' => 'daemon_unavailable',
                'container_exists' => false,
                'container_running' => false,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diffCaddyContainer($node, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.docker_runtime_unavailable')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.docker_runtime_unavailable')?->detail['runtime_status'])
            ->toBe('daemon_unavailable')
            ->and(proxyProbeIssue($drift, 'proxy.caddy_container_missing'))
            ->toBeNull();
    });
});

describe('s3 upload-safe proxy route probe', function (): void {
    it('passes s3 service route when the observed file hash matches the upload-safe rendered source hash', function (): void {
        $node = createTestAppHostNode();
        $renderer = new ProxyRouteRenderer;
        $config = [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
            ],
        ];
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $uploadSafeHash = $renderer->sourceHash($route);
        $route->forceFill(['source_hash' => $uploadSafeHash])->save();

        $snapshot = new ProbeSnapshot([
            's3.orbit' => [
                'route_exists' => true,
                'route_hash' => $uploadSafeHash,
                'cert_exists' => true,
                'key_exists' => true,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.route_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.route_mismatch'))
            ->toBeNull();
    });

    it('detects drift when the s3 service route on disk lacks upload-safe streaming directives', function (): void {
        $node = createTestAppHostNode();
        $renderer = new ProxyRouteRenderer;
        $config = [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
            ],
        ];
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $uploadSafeHash = $renderer->sourceHash($route);
        $route->forceFill(['source_hash' => $uploadSafeHash])->save();

        // The node still has the old route without upload-safe streaming.
        $oldHashWithoutStreaming = str_repeat('0', 64);
        $snapshot = new ProbeSnapshot([
            's3.orbit' => [
                'route_exists' => true,
                'route_hash' => $oldHashWithoutStreaming,
                'cert_exists' => true,
                'key_exists' => true,
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.route_mismatch')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.route_mismatch')?->detail['expected_hash'] ?? null)
            ->toBe($uploadSafeHash)
            ->and(proxyProbeIssue($drift, 'proxy.route_mismatch')?->detail['observed_hash'] ?? null)
            ->toBe($oldHashWithoutStreaming);
    });

    it('passes observed s3 ingress route artifact rendered with upload-safe streaming settings', function (): void {
        $edge = Node::factory()->ingress()->create(['status' => 'active']);
        $renderer = new ProxyRouteRenderer;
        $config = [
            'placement' => 'ingress',
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
            'router_upstream' => [
                'node_id' => 12,
                'node' => 'gateway-1',
                'url' => 'http://10.6.0.1:80',
            ],
            'tls' => [
                'cert_path' => '/etc/orbit/certs/s3.example.com.crt',
                'key_path' => '/etc/orbit/certs/s3.example.com.key',
            ],
        ];
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 's3.example.com',
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $uploadSafeHash = $renderer->sourceHash($route);
        $route->forceFill(['source_hash' => $uploadSafeHash])->save();

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([
            's3.example.com' => [
                'public' => [
                    'route_exists' => true,
                    'route_hash' => $uploadSafeHash,
                    'cert_path' => '/etc/orbit/certs/s3.example.com.crt',
                    'key_path' => '/etc/orbit/certs/s3.example.com.key',
                    'cert_exists' => true,
                    'key_exists' => true,
                ],
                'router' => [],
                'backends' => [],
            ],
        ]));

        expect(proxyProbeIssue($drift, 'proxy.public_route_missing'))
            ->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.public_route_mismatch'))
            ->toBeNull();
    });
});

describe('legacy php_fastcgi route convergence after Docker-first runtime backfill', function (): void {
    it(
        'reports proxy.route_mismatch when an observed legacy php_fastcgi Caddyfile hash differs from the post-backfill Docker-first reverse_proxy source_hash',
        function (): void {
            // Simulates the post-migration state: the backfill recomputed
            // source_hash to match Docker-first reverse_proxy content. The node
            // still serves the OLD legacy php_fastcgi Caddyfile, so its observed
            // hash should NOT match — proving doctor will detect drift and
            // restore can converge to the Docker-first artifact.
            $node = createTestAppHostNode();
            $app = App::factory()->for($node, 'node')->create([
                'name' => 'legacy-docs',
                'document_root' => 'public',
            ]);

            $route = ProxyRoute::factory()
                ->for($node, 'node')
                ->for($app, 'app')
                ->create([
                    'domain' => 'legacy-docs.test',
                    'owner_type' => 'app',
                    'kind' => 'app',
                    'config' => [
                        'document_root' => '/home/orbit/apps/legacy-docs/public',
                        'runtime_upstream' => 'http://orbit-app-legacy-docs:8080',
                        'php_socket' => null,
                        'tls' => [
                            'cert_path' => '/etc/orbit/certs/legacy-docs.test.crt',
                            'key_path' => '/etc/orbit/certs/legacy-docs.test.key',
                        ],
                    ],
                ]);

            $dockerFirstHash = hash('sha256', new ProxyRouteRenderer()->render($route));
            $route->forceFill(['source_hash' => $dockerFirstHash])->save();

            // The node returns a hash that represents the LEGACY php_fastcgi
            // Caddyfile still on disk — different from the Docker-first hash.
            $observedLegacyHash = str_repeat('f', 64);
            $shell = new ProxyProbeRecordingRemoteShell(
                "1\t{$observedLegacyHash}\t/etc/orbit/certs/legacy-docs.test.crt\t/etc/orbit/certs/legacy-docs.test.key\t1\t1\n",
            );

            $snapshot = proxyProbeWithRemoteShell($shell)->introspect($route);
            $drift = new ProxyRouteProbe()->diff($route, $snapshot);

            expect(proxyProbeIssue($drift, 'proxy.route_mismatch')?->kind)
                ->toBe(DriftKind::Divergent)
                ->and(proxyProbeIssue($drift, 'proxy.route_mismatch')?->detail['expected_hash'] ?? null)
                ->toBe($dockerFirstHash)
                ->and(proxyProbeIssue($drift, 'proxy.route_mismatch')?->detail['observed_hash'] ?? null)
                ->toBe($observedLegacyHash);
        },
    );

    it(
        'reports proxy.backend_route_mismatch when an observed legacy private-backend php_fastcgi Caddyfile hash differs from the post-backfill Docker-first backend_artifact source_hash',
        function (): void {
            $edge = Node::factory()->create([
                'name' => 'edge-1',
                'status' => 'active',
                'wireguard_address' => '10.6.0.4',
            ]);
            $backend = Node::factory()->create([
                'name' => 'web-1',
                'status' => 'active',
                'wireguard_address' => '10.6.0.21',
            ]);
            assignProxyProbeRole($edge, 'ingress');
            assignProxyProbeRole($backend, 'app-prod');
            $app = App::factory()->create([
                'name' => 'legacy-docs',
                'document_root' => 'public',
                'node_id' => $backend->id,
            ]);

            // Build a route with Docker-first backend_artifact source_hash (the
            // value the backfill would have written for an ingress topology).
            $artifact = [
                'node_id' => $backend->id,
                'bind' => '10.6.0.21',
                'document_root' => '/home/orbit/apps/legacy-docs/public',
                'runtime_upstream' => 'http://orbit-app-legacy-docs:8080',
                'php_socket' => null,
            ];
            $route = ProxyRoute::factory()->create([
                'node_id' => $edge->id,
                'domain' => 'legacy-docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'app_id' => $app->id,
                'source_hash' => str_repeat('a', 64),
                'config' => [
                    'placement' => 'ingress',
                    'router_artifact' => ['node_id' => $backend->id, 'source_hash' => str_repeat('c', 64)],
                    'router_backend_pool' => [[
                        'node_id' => $backend->id,
                        'node' => 'web-1',
                        'url' => 'http://10.6.0.21:80',
                    ]],
                    'backend_artifacts' => [array_merge($artifact, [
                        'source_hash' => 'placeholder',
                    ])],
                ],
            ]);
            $route->loadMissing('app');

            $dockerFirstBackendHash = hash('sha256', new ProxyRouteRenderer()->renderPrivateBackend($route, $artifact));
            $config = $route->config;
            $config['backend_artifacts'][0]['source_hash'] = $dockerFirstBackendHash;
            $route->forceFill(['config' => $config])->save();

            // Observed legacy backend hash on the backend node differs from the
            // post-backfill expected hash.
            $observedLegacyBackendHash = str_repeat('9', 64);
            $snapshot = new ProbeSnapshot([
                'legacy-docs.test' => [
                    'public' => ['route_exists' => true, 'route_hash' => str_repeat('a', 64)],
                    'router' => ['route_exists' => true, 'route_hash' => str_repeat('c', 64)],
                    'backends' => [
                        $backend->id => ['route_exists' => true, 'route_hash' => $observedLegacyBackendHash],
                    ],
                ],
            ]);

            $drift = new ProxyRouteProbe()->diff($route, $snapshot);

            expect(proxyProbeIssue($drift, 'proxy.backend_route_mismatch')?->kind)
                ->toBe(DriftKind::Divergent)
                ->and(proxyProbeIssue($drift, 'proxy.backend_route_mismatch')?->detail['expected_hash'] ?? null)
                ->toBe($dockerFirstBackendHash)
                ->and(proxyProbeIssue($drift, 'proxy.backend_route_mismatch')?->detail['observed_hash'] ?? null)
                ->toBe($observedLegacyBackendHash);
        },
    );
});

final class ProxyProbeRecordingRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /** @var list<string> */
    public array $scripts = [];

    public function __construct(
        private readonly string $stdout,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(exitCode: 0, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

// ---------------------------------------------------------------------------
// S3 service route eligibility fix (ProxyRouteProbe::canServeProxyRoutes)
// ---------------------------------------------------------------------------

describe('s3 service route node eligibility in ProxyRouteProbe', function (): void {
    it('s3 healthy s3.orbit service route on a router-only node does NOT produce proxy.node_invalid', function (): void {
        $node = Node::factory()->create(['name' => 'router-only', 'status' => 'active']);
        assignProxyProbeRole($node, 'router');

        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
                'upstreams' => [
                    ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.node_invalid'))->toBeNull();
    });

    it('s3 public host ingress route on an ingress node still resolves via ingress eligibility (no false proxy.node_invalid)', function (): void {
        $node = Node::factory()->create(['name' => 'ingress-only', 'status' => 'active']);
        assignProxyProbeRole($node, 'ingress');

        $routerNode = Node::factory()->create([
            'name' => 'router-only',
            'wireguard_address' => '10.6.0.1',
            'status' => 'active',
        ]);
        assignProxyProbeRole($routerNode, 'router');

        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 's3.example.com',
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
                'router_upstream' => [
                    'node_id' => $routerNode->id,
                    'node' => 'router-only',
                    'url' => 'http://10.6.0.1:80',
                ],
                'tls' => [
                    'cert_path' => '/etc/orbit/certs/s3.example.com.crt',
                    'key_path' => '/etc/orbit/certs/s3.example.com.key',
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.node_invalid'))->toBeNull();
    });

    it('s3 s3.orbit service route on a non-router node DOES produce proxy.node_invalid', function (): void {
        $node = Node::factory()->create(['name' => 'ingress-only', 'status' => 'active']);
        assignProxyProbeRole($node, 'ingress');

        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
                'upstreams' => [
                    ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
                ],
            ],
        ]);

        $drift = new ProxyRouteProbe()->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.node_invalid'))->not->toBeNull();
    });
});

final readonly class ProxyProbeScriptExecutor implements RunsInternalCommands
{
    public function __construct(
        private RemoteShell $shell,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $payload = json_decode((string) $transportOptions['input'], true, flags: JSON_THROW_ON_ERROR);
        $result = $this->shell->run($node, (string) $payload['script'], $transportOptions);

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
}

final class ProxyProbeCaddyContainerShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    public function __construct(
        private readonly string $runtimeOutput,
        private readonly string $existsOutput,
        private readonly string $runningOutput,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->runtimeOutput."\t".$this->existsOutput."\t".$this->runningOutput."\n",
            stderr: '',
            durationMs: 1,
        );
    }
}
