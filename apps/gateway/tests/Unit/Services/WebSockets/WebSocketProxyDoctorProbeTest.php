<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

// ---------------------------------------------------------------------------
// Topology helpers
// ---------------------------------------------------------------------------

function wsProbeRouter(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'router',
        'status' => 'active',
    ]);

    return $node;
}

function wsProbeIngress(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'edge-1',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    return $node;
}

function wsProbeActiveWebSocketNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);

    return $node;
}

// ---------------------------------------------------------------------------
// router_route_orphaned — websocket.orbit exists, no active websocket role
// ---------------------------------------------------------------------------

it('ws router_route_orphaned when websocket.orbit route exists and no active websocket role remains', function (): void {
    $router = wsProbeRouter();
    $backend = wsProbeActiveWebSocketNode();
    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
    $backend->roleAssignments()->where('role', 'websocket')->update(['status' => 'removing']);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);

    $entry = $drift[array_search(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Extra);
})->group('websocket', 'proxy-doctor');

it('ws router_route_orphaned not emitted when active websocket role exists', function (): void {
    $router = wsProbeRouter();
    wsProbeActiveWebSocketNode();

    ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
    ]);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('websocket', 'proxy-doctor');

it('ws router_route_orphaned not emitted when websocket.orbit route does not exist', function (): void {
    $router = wsProbeRouter();
    // No active websocket role, no route row either

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('websocket', 'proxy-doctor');

it('ws router_route_orphaned not emitted for non-router nodes', function (): void {
    $ingress = wsProbeIngress();
    // No active websocket role

    ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $ingress->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
    ]);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('websocket', 'proxy-doctor');

it('limits app-scoped public websocket drift to the selected app', function (): void {
    $ingress = wsProbeIngress();
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    wsProbeActiveWebSocketNode();
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

    foreach ([
        ['name' => 'hauzer-production', 'host' => 'ws.hauzer.app'],
        ['name' => 'mealou-production', 'host' => 'ws.mealou.app'],
    ] as $definition) {
        $app = App::factory()->create([
            'name' => $definition['name'],
        ]);
        $instance = Instance::factory()->for($app)->create([
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        AppWebSocketBinding::factory()->create([
            'instance_id' => $instance->id,
            'public_hosts' => [$definition['host']],
        ]);
    }

    $drift = app(WebSocketProxyDoctorProbe::class)->drift($ingress, 'hauzer-production');

    expect(collect($drift)->pluck('detail.domain')->all())
        ->toBe(['ws.hauzer.app']);
})->group('websocket', 'proxy-doctor');

// ---------------------------------------------------------------------------
// restore — router_route_orphaned removes the service route row
// ---------------------------------------------------------------------------

it('ws restore router_route_orphaned removes the websocket.orbit service route row', function (): void {
    $router = wsProbeRouter();
    $backend = wsProbeActiveWebSocketNode();
    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
    $backend->roleAssignments()->where('role', 'websocket')->update(['status' => 'removing']);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: WebSocketProxyDoctorProbe::RouterRouteOrphanedKey,
        kind: DriftKind::Extra,
        summary: 'Orphaned websocket.orbit route.',
    );

    $result = $probe->restore($router, $entry);

    expect($result)
        ->not
        ->toBeNull()
        ->and($result['key'])
        ->toBe(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey)
        ->and($result['status'])
        ->toBe('completed')
        ->and($result['mode'])
        ->toBe('fix')
        ->and(ProxyRoute::query()->where('domain', WebSocketRouteRegistrar::ServiceDomain)->exists())
        ->toBeFalse();
})->group('websocket', 'proxy-doctor');

it('ws doctor does not classify or remove another owner at the reserved service domain', function (): void {
    $router = wsProbeRouter();
    $route = ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080']],
    ]);
    $probe = app(WebSocketProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: WebSocketProxyDoctorProbe::RouterRouteOrphanedKey,
        kind: DriftKind::Extra,
        summary: 'Orphaned websocket.orbit route.',
    );

    $drift = $probe->drift($router);
    $result = $probe->restore($router, $entry);

    expect(collect($drift)->pluck('key')->all())
        ->not
        ->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey)
        ->and($result)
        ->toBeNull()
        ->and($route->fresh()?->owner_type)
        ->toBe('custom');
})->group('websocket', 'proxy-doctor');

it('ws doctor does not restore active drift by converting another owner', function (): void {
    $router = wsProbeRouter();
    wsProbeActiveWebSocketNode();
    $route = ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080']],
    ]);
    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);
    $entry = collect($drift)->firstWhere('key', WebSocketProxyDoctorProbe::RouterRouteKey);

    expect($entry)->toBeInstanceOf(DriftEntry::class);
    assert($entry instanceof DriftEntry);

    expect($entry->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($entry->detail['reason'] ?? null)
        ->toBe('ownership_conflict')
        ->and($probe->restore($router, $entry))
        ->toBeNull()
        ->and($route->fresh()?->owner_type)
        ->toBe('custom');
})->group('websocket', 'proxy-doctor');

it('ws restore returns null for unknown key', function (): void {
    $router = wsProbeRouter();

    $probe = app(WebSocketProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: 'proxy.unknown.key',
        kind: DriftKind::Missing,
        summary: 'test',
    );

    $result = $probe->restore($router, $entry);
    expect($result)->toBeNull();
})->group('websocket', 'proxy-doctor');
