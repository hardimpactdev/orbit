<?php

declare(strict_types=1);

use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
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
    $ingress = Node::factory()->ingress()->create([
        'name' => 'edge-1',
        'wireguard_address' => '10.6.0.10',
    ]);
    $router = Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    $appNode = Node::factory()->appProd()->create([
        'name' => 'app-prod-1',
        'wireguard_address' => '10.6.0.21',
    ]);

    $appNode->roleAssignments()
        ->where('role', 'app-prod')
        ->update(['settings' => ['ingress_node_id' => $ingress->id]]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'domain' => 'docs.example.com',
    ]);

    return [$app, $ingress, $router];
}

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

it('syncs the service route on the active router with websocket backends', function (): void {
    $router = Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    $firstBackend = Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-1',
        'wireguard_address' => '10.6.0.44',
    ]);
    $secondBackend = Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-2',
        'wireguard_address' => '10.6.0.45',
    ]);

    Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-inactive',
        'status' => 'inactive',
        'wireguard_address' => '10.6.0.46',
    ]);

    $route = app(WebSocketRouteRegistrar::class)->syncServiceRoute();

    expect($route->domain)->toBe('websocket.orbit')
        ->and($route->node_id)->toBe($router->id)
        ->and($route->app_id)->toBeNull()
        ->and($route->workspace_id)->toBeNull()
        ->and($route->owner_type)->toBe('websocket')
        ->and($route->kind)->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'protocol' => 'websocket',
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => 'router-1',
                'url' => 'http://10.6.0.2:80',
            ],
            'router_backend_pool' => [
                [
                    'node_id' => $firstBackend->id,
                    'node' => 'ws-1',
                    'url' => 'https://ws-1.websocket.orbit:8080',
                ],
                [
                    'node_id' => $secondBackend->id,
                    'node' => 'ws-2',
                    'url' => 'https://ws-2.websocket.orbit:8080',
                ],
            ],
            'upstreams' => [
                [
                    'node_id' => $firstBackend->id,
                    'node' => 'ws-1',
                    'scheme' => 'https',
                    'host' => 'ws-1.websocket.orbit',
                    'port' => 8080,
                    'url' => 'https://ws-1.websocket.orbit:8080',
                ],
                [
                    'node_id' => $secondBackend->id,
                    'node' => 'ws-2',
                    'scheme' => 'https',
                    'host' => 'ws-2.websocket.orbit',
                    'port' => 8080,
                    'url' => 'https://ws-2.websocket.orbit:8080',
                ],
            ],
            'tls' => [
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
            ],
        ])
        ->and($route->source_hash)->toBe(app(ProxyRouteRenderer::class)->sourceHash($route))
        ->and(ProxyRoute::query()->where('domain', 'websocket.orbit')->count())->toBe(1);
});

it('updates the service route when websocket backends change', function (): void {
    Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    $staleBackend = Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-old',
        'status' => 'inactive',
        'wireguard_address' => '10.6.0.40',
    ]);
    $activeBackend = Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-new',
        'wireguard_address' => '10.6.0.41',
    ]);

    ProxyRoute::factory()->create([
        'domain' => 'websocket.orbit',
        'node_id' => $staleBackend->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080']],
    ]);

    $route = app(WebSocketRouteRegistrar::class)->syncServiceRoute();

    expect($route->owner_type)->toBe('websocket')
        ->and($route->config['router_backend_pool'])->toBe([
            [
                'node_id' => $activeBackend->id,
                'node' => 'ws-new',
                'url' => 'https://ws-new.websocket.orbit:8080',
            ],
        ])
        ->and(ProxyRoute::query()->where('domain', 'websocket.orbit')->count())->toBe(1);
});

it('requires an active router node before syncing the service route', function (): void {
    Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The websocket service route requires an active router node.');

it('requires at least one active websocket backend before syncing the service route', function (): void {
    Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);

    app(WebSocketRouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The websocket service route requires at least one active websocket node.');

it('syncs public websocket hosts as ingress routes that target router and websocket.orbit', function (): void {
    [$app, $ingress, $router] = websocketRouteRegistrarAppWithIngress();
    $binding = AppWebSocketBinding::factory()->create([
        'app_id' => $app->id,
        'public_hosts' => ['ws.example.com', 'events.example.com'],
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    $routes = ProxyRoute::query()
        ->where('owner_type', 'app-websocket')
        ->orderBy('domain')
        ->get();
    $route = $routes->firstWhere('domain', 'ws.example.com');

    expect($routes)->toHaveCount(2)
        ->and($routes->pluck('domain')->all())->toBe(['events.example.com', 'ws.example.com'])
        ->and($route)->not->toBeNull()
        ->and($route->node_id)->toBe($ingress->id)
        ->and($route->app_id)->toBe($app->id)
        ->and($route->workspace_id)->toBeNull()
        ->and($route->kind)->toBe('proxy')
        ->and($route->config)->toMatchArray([
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
                    'node_id' => $router->id,
                    'node' => 'router-1',
                    'url' => 'https://websocket.orbit',
                ],
            ],
            'tls' => [
                'cert_path' => '/home/orbit/.config/orbit/certs/ws.example.com.crt',
                'key_path' => '/home/orbit/.config/orbit/certs/ws.example.com.key',
            ],
        ])
        ->and($route->source_hash)->toBe(app(ProxyRouteRenderer::class)->sourceHash($route))
        ->and($route->config['router_artifact'])->toMatchArray([
            'node_id' => $router->id,
            'node' => 'router-1',
        ]);

    expect($route->config['router_artifact']['source_hash'])->toBe(hash('sha256', app(ProxyRouteRenderer::class)->renderRouterRoute(new ProxyRoute([
        'node_id' => $router->id,
        'domain' => 'ws.example.com',
        'app_id' => $app->id,
        'owner_type' => 'app-websocket',
        'kind' => 'proxy',
        'config' => $route->config,
    ]))));
});

it('removes stale public websocket routes for the binding app', function (): void {
    [$app, $ingress] = websocketRouteRegistrarAppWithIngress();
    $binding = AppWebSocketBinding::factory()->create([
        'app_id' => $app->id,
        'public_hosts' => ['ws-new.example.com'],
    ]);

    ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'domain' => 'ws-old.example.com',
        'owner_type' => 'app-websocket',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit']],
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    expect(ProxyRoute::query()->where('domain', 'ws-old.example.com')->exists())->toBeFalse()
        ->and(ProxyRoute::query()->where('domain', 'ws-new.example.com')->exists())->toBeTrue();
});

it('removes public websocket routes when the binding is disabled', function (): void {
    [$app, $ingress] = websocketRouteRegistrarAppWithIngress();
    $binding = AppWebSocketBinding::factory()->create([
        'app_id' => $app->id,
        'enabled' => false,
        'public_hosts' => ['ws.example.com'],
    ]);

    ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'domain' => 'ws.example.com',
        'owner_type' => 'app-websocket',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit']],
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    expect(ProxyRoute::query()->where('domain', 'ws.example.com')->exists())->toBeFalse();
});

it('requires an ingress route when public websocket hosts are configured', function (): void {
    Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    $appNode = Node::factory()->appProd()->create([
        'name' => 'app-prod-1',
        'wireguard_address' => '10.6.0.21',
    ]);
    $app = App::factory()->create([
        'node_id' => $appNode->id,
    ]);
    $binding = AppWebSocketBinding::factory()->create([
        'app_id' => $app->id,
        'public_hosts' => ['ws.example.com'],
    ]);

    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);
})->throws(DomainException::class, 'The selected ingress node is unavailable.');
