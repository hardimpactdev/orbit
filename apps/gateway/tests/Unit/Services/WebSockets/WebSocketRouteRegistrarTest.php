<?php

declare(strict_types=1);

use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{0: App, 1: Node, 2: Node}
 */
function websocketRouteRegistrarAppWithIngress(): array
{
    $ingress = Node::factory()
        ->ingress()
        ->create([
            'name' => 'edge-1',
            'wireguard_address' => '10.6.0.10',
        ]);
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.4',
        ]);
    $appNode = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-prod-1',
            'wireguard_address' => '10.6.0.21',
        ]);

    $appNode
        ->roleAssignments()
        ->where('role', 'app-prod')
        ->update(['settings' => ['ingress_node_id' => $ingress->id]]);

    $app = App::factory()->create([
        'name' => 'docs',
    ]);
    Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $appNode->id,
            domain: 'docs.example.com',
        ),
    ]);

    return [$app, $ingress, $router];
}

function websocketRouteRegistrarInstance(App $app): Instance
{
    return $app->instances()->firstOrFail();
}

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

it('syncs the service route on the active router with the websocket backend', function (): void {
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    $firstBackend = Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.44',
        ]);

    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'websocket-inactive',
            'status' => 'inactive',
            'wireguard_address' => '10.6.0.46',
        ]);

    $route = app(WebSocketRouteRegistrar::class)->syncServiceRoute();

    expect($route->domain)
        ->toBe('websocket.orbit')
        ->and($route->node_id)
        ->toBe($router->id)
        ->and($route->app_id)
        ->toBeNull()
        ->and($route->workspace_id)
        ->toBeNull()
        ->and($route->owner_type)
        ->toBe('router')
        ->and($route->kind)
        ->toBe('proxy')
        ->and($route->config)
        ->toMatchArray([
            'protocol' => 'websocket',
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => 'router-1',
                'url' => 'http://10.6.0.2:80',
            ],
            'router_backend_pool' => [
                [
                    'node_id' => $firstBackend->id,
                    'node' => 'app-dev-1',
                    'url' => 'https://10.6.0.44:8080',
                ],
            ],
            'router_backend_tls' => [
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ],
            'upstreams' => [
                [
                    'node_id' => $firstBackend->id,
                    'node' => 'app-dev-1',
                    'scheme' => 'https',
                    'host' => '10.6.0.44',
                    'backend_name' => '10.6.0.44',
                    'port' => 8080,
                    'url' => 'https://10.6.0.44:8080',
                ],
            ],
            'tls' => [
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                'key_path' => '/etc/orbit/certs/websocket.orbit.key',
            ],
        ])
        ->and($route->source_hash)
        ->toBe(app(ProxyRouteRenderer::class)->sourceHash($route))
        ->and(ProxyRoute::query()->where('domain', 'websocket.orbit')->count())
        ->toBe(1);
});

it('fails clearly when more than one websocket backend is active', function (): void {
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.44',
        ]);
    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'websocket-dedicated-1',
            'wireguard_address' => '10.6.0.45',
        ]);

    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The websocket service route supports one active websocket backend.');

it('does not overwrite a custom route at the reserved websocket service domain', function (): void {
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    $staleBackend = Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'ws-old',
            'status' => 'inactive',
            'wireguard_address' => '10.6.0.40',
        ]);
    $activeBackend = Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.41',
        ]);

    ProxyRoute::factory()->create([
        'domain' => 'websocket.orbit',
        'node_id' => $staleBackend->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080']],
    ]);

    $stored = ProxyRoute::query()->where('domain', 'websocket.orbit')->sole();

    expect(fn () => app(WebSocketRouteRegistrar::class)->syncServiceRoute())
        ->toThrow(
            RuntimeException::class,
            "WebSocket service route 'websocket.orbit' conflicts with existing ownership.",
        );

    expect($stored->fresh()?->only(['node_id', 'owner_type', 'kind', 'config']))
        ->toBe([
            'node_id' => $staleBackend->id,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080']],
        ])
        ->and($activeBackend->exists)
        ->toBeTrue()
        ->and($router->exists)
        ->toBeTrue();
});

it('does not overwrite malformed websocket service ownership', function (array $attributes): void {
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.41',
        ]);
    $route = ProxyRoute::query()->create([
        'node_id' => $router->id,
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
        'source_hash' => str_repeat('a', 64),
        ...$attributes,
    ]);
    $original = $route->fresh()->getAttributes();

    expect(fn () => app(WebSocketRouteRegistrar::class)->syncServiceRoute())
        ->toThrow(
            RuntimeException::class,
            "WebSocket service route 'websocket.orbit' conflicts with existing ownership.",
        );

    expect($route->fresh()?->getAttributes())->toBe($original);
})->with([
    'incomplete stable config' => [[]],
    'wrong kind' => [['kind' => 'redirect']],
    'wrong protocol' => [['config' => ['protocol' => 'analytics']]],
    'wrong node identity' => [fn (): array => ['node_id' => Node::factory()->create()->id]],
    'stray app identity' => [fn (): array => ['app_id' => App::factory()->create()->id]],
    'stray workspace identity' => [fn (): array => ['workspace_id' => Workspace::factory()->create()->id]],
    'stray instance identity' => [fn (): array => ['instance_id' => Instance::factory()->create()->id]],
]);

it('does not remove incomplete websocket service ownership', function (): void {
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    $route = ProxyRoute::query()->create([
        'node_id' => $router->id,
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
        'source_hash' => str_repeat('a', 64),
    ]);

    app(WebSocketRouteRegistrar::class)->removeServiceRoute();

    expect($route->fresh())->not->toBeNull();
});

it('requires an active router node before syncing the service route', function (): void {
    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.44',
        ]);

    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The websocket service route requires an active router node.');

it('requires at least one active websocket backend before syncing the service route', function (): void {
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);

    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The websocket service route requires at least one active websocket backend.');

it('requires websocket backends to have a WireGuard address', function (): void {
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '',
        ]);

    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The websocket backend requires a WireGuard address.');

it('syncs public websocket hosts as ingress routes that target router and websocket.orbit', function (): void {
    [$app, $ingress, $router] = websocketRouteRegistrarAppWithIngress();
    $instance = websocketRouteRegistrarInstance($app);
    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => $instance->id,
        'public_hosts' => ['ws.example.com', 'events.example.com'],
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    $routes = ProxyRoute::query()
        ->where('owner_type', 'app-websocket')
        ->orderBy('domain')
        ->get();
    $route = $routes->firstWhere('domain', 'ws.example.com');

    expect($routes)
        ->toHaveCount(2)
        ->and($routes->pluck('domain')->all())
        ->toBe(['events.example.com', 'ws.example.com'])
        ->and($route)
        ->not
        ->toBeNull()
        ->and($route->node_id)
        ->toBe($ingress->id)
        ->and($route->app_id)
        ->toBe($app->id)
        ->and($route->instance_id)
        ->toBe($instance->id)
        ->and($route->workspace_id)
        ->toBeNull()
        ->and($route->kind)
        ->toBe('proxy')
        ->and($route->config)
        ->toMatchArray([
            'placement' => 'ingress',
            'ingress_node_id' => $ingress->id,
            'protocol' => 'websocket',
            'target' => [
                'type' => 'websocket',
                'value' => 'https://websocket.orbit',
            ],
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => 'router-1',
                'url' => 'http://10.6.0.2:80',
            ],
            'router_backend_pool' => [
                [
                    'node_id' => Node::query()->where('name', 'app-dev-1')->value('id'),
                    'node' => 'app-dev-1',
                    'url' => 'https://10.6.0.4:8080',
                ],
            ],
            'router_backend_tls' => [
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ],
            'tls' => [
                'cert_path' => '/etc/orbit/certs/ws.example.com.crt',
                'key_path' => '/etc/orbit/certs/ws.example.com.key',
            ],
        ])
        ->and($route->source_hash)
        ->toBe(app(ProxyRouteRenderer::class)->sourceHash($route))
        ->and($route->config['router_artifact'])
        ->toMatchArray([
            'node_id' => $router->id,
            'node' => 'router-1',
        ]);

    expect($route->config['router_artifact']['source_hash'])
        ->toBe(hash('sha256', app(ProxyRouteRenderer::class)->renderRouterRoute(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => 'ws.example.com',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => $route->config,
        ]))));
});

it('rejects malformed or differently-owned routes at a public websocket host', function (string $invalidity): void {
    [$app, $ingress] = websocketRouteRegistrarAppWithIngress();
    $instance = websocketRouteRegistrarInstance($app);
    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => $instance->id,
        'public_hosts' => ['ws.example.com'],
    ]);
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'workspace_id' => null,
        'domain' => 'ws.example.com',
        'owner_type' => 'app-websocket',
        'kind' => 'proxy',
        'config' => [
            'placement' => 'ingress',
            'ingress_node_id' => $ingress->id,
            'protocol' => 'websocket',
            'target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit'],
        ],
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
        $route->forceFill(['kind' => 'app'])->save();
    }

    if ($invalidity === 'workspace identity') {
        $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
        $route->forceFill(['workspace_id' => $workspace->id])->save();
    }

    if ($invalidity === 'different valid owner') {
        $route->forceFill([
            'instance_id' => Instance::factory()->for($app)->create(['name' => 'preview'])->id,
        ])->save();
    }

    if ($invalidity === 'wrong node') {
        $route->forceFill(['node_id' => Node::factory()->create()->id])->save();
    }

    if ($invalidity === 'wrong protocol') {
        $route->forceFill(['config' => ['protocol' => 'analytics']])->save();
    }

    $original = $route->fresh()->getAttributes();

    expect(fn (): mixed => app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding))
        ->toThrow(
            RuntimeException::class,
            "WebSocket public host 'ws.example.com' conflicts with an existing proxy route.",
        )
        ->and($route->fresh()->getAttributes())
        ->toBe($original);
})->with([
    'missing app',
    'missing instance',
    'conflicting app',
    'wrong kind',
    'workspace identity',
    'different valid owner',
    'wrong node',
    'wrong protocol',
]);

it('treats a stable public websocket config mismatch as an ownership conflict', function (): void {
    [$app, $ingress] = websocketRouteRegistrarAppWithIngress();
    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => websocketRouteRegistrarInstance($app)->id,
        'public_hosts' => ['ws.example.com'],
    ]);
    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);
    $route = ProxyRoute::query()->where('domain', 'ws.example.com')->firstOrFail();
    $config = $route->config;
    $config['target']['value'] = 'https://custom.example.test';
    $route->forceFill(['config' => $config])->save();
    $original = $route->fresh()->getAttributes();
    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($ingress, $app->name);
    $staleEntry = new DriftEntry(
        family: 'proxy',
        key: WebSocketProxyDoctorProbe::PublicRouteKey,
        kind: DriftKind::Divergent,
        summary: 'Stale public WebSocket repair entry.',
        detail: [
            'binding_id' => $binding->id,
            'domain' => 'ws.example.com',
        ],
    );

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($drift[0]->detail['reason'] ?? null)
        ->toBe('ownership_conflict')
        ->and($probe->restore($ingress, $drift[0]))
        ->toBeNull()
        ->and($probe->restore($ingress, $staleEntry))
        ->toBeNull()
        ->and(fn (): mixed => app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding))
        ->toThrow(
            RuntimeException::class,
            "WebSocket public host 'ws.example.com' conflicts with an existing proxy route.",
        )
        ->and($route->fresh()->getAttributes())
        ->toBe($original);
});

it('removes stale public websocket routes for the binding app', function (): void {
    [$app, $ingress] = websocketRouteRegistrarAppWithIngress();
    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => websocketRouteRegistrarInstance($app)->id,
        'public_hosts' => ['ws-old.example.com'],
    ]);
    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);
    $validStaleRoute = ProxyRoute::query()->where('domain', 'ws-old.example.com')->firstOrFail();
    $binding->forceFill(['public_hosts' => ['ws-new.example.com']])->save();
    $malformedStaleRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $binding->instance_id,
        'domain' => 'ws-malformed.example.com',
        'owner_type' => 'app-websocket',
        'kind' => 'proxy',
    ]);
    $malformedStaleRoute->forceFill([
        'app_id' => App::factory()->create(['name' => 'compatibility'])->id,
    ])->save();
    $strayForeignKeyRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => null,
        'instance_id' => $binding->instance_id,
        'domain' => 'ws-custom.example.com',
        'owner_type' => 'custom',
        'kind' => 'proxy',
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    expect(ProxyRoute::query()->whereKey($validStaleRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($malformedStaleRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($strayForeignKeyRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->where('domain', 'ws-new.example.com')->exists())
        ->toBeTrue();
});

it('removes public websocket routes when the binding is disabled', function (): void {
    [$app, $ingress] = websocketRouteRegistrarAppWithIngress();
    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => websocketRouteRegistrarInstance($app)->id,
        'enabled' => true,
        'public_hosts' => ['ws.example.com'],
    ]);
    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);
    $validRoute = ProxyRoute::query()->where('domain', 'ws.example.com')->firstOrFail();
    $binding->forceFill(['enabled' => false])->save();
    $malformedRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $binding->instance_id,
        'domain' => 'ws-malformed.example.com',
        'owner_type' => 'app-websocket',
        'kind' => 'proxy',
    ]);
    $malformedRoute->forceFill([
        'app_id' => App::factory()->create(['name' => 'compatibility'])->id,
    ])->save();
    $strayForeignKeyRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => null,
        'instance_id' => $binding->instance_id,
        'domain' => 'ws-custom.example.com',
        'owner_type' => 'custom',
        'kind' => 'proxy',
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    expect(ProxyRoute::query()->whereKey($validRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($malformedRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($strayForeignKeyRoute->id)->exists())
        ->toBeTrue();
});

it('requires an ingress route when public websocket hosts are configured', function (): void {
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    $appNode = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-prod-1',
            'wireguard_address' => '10.6.0.21',
        ]);
    $app = App::factory()->create();
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $appNode->id),
    ]);
    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => $instance->id,
        'public_hosts' => ['ws.example.com'],
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);
})->throws(DomainException::class, 'The selected ingress node is unavailable.');
