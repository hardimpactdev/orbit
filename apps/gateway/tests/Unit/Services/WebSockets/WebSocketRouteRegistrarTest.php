<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

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
