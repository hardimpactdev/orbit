<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Gateway\GatewayCaddyRouteRenderer;
use App\Services\Gateway\GatewayLeafIdentity;
use App\Services\Metrics\MetricsServiceRoute;
use App\Services\Proxy\NonInstanceProxyRouteOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{route: ProxyRoute, backend: Node|null}
 */
function complete_non_instance_route(string $family): array
{
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.1',
        ]);

    if ($family === 'custom') {
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $node->id,
                'domain' => 'custom.test',
                'owner_type' => 'custom',
                'kind' => 'proxy',
                'config' => [
                    'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                    'upstream' => 'http://127.0.0.1:5173',
                ],
            ]),
            'backend' => null,
        ];
    }

    if ($family === 'tool') {
        $node = Node::factory()->agent()->create(['name' => 'agent-1', 'tld' => 'agent']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $node->id,
                'domain' => 'hermes.agent',
                'owner_type' => 'tool',
                'kind' => 'proxy',
                'config' => [
                    'owner_name' => 'hermes',
                    'upstream' => 'http://host.docker.internal:8080',
                    'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
                ],
            ]),
            'backend' => null,
        ];
    }

    if ($family === 'analytics') {
        $backend = Node::factory()
            ->withActiveRole('analytics')
            ->create([
                'name' => 'analytics-1',
                'wireguard_address' => '10.6.0.20',
            ]);
        $upstream = [
            'node_id' => $backend->id,
            'node' => $backend->name,
            'scheme' => 'http',
            'host' => '10.6.0.20',
            'port' => 8000,
            'url' => 'http://10.6.0.20:8000',
        ];

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $router->id,
                'domain' => 'analytics.orbit',
                'owner_type' => 'router',
                'kind' => 'proxy',
                'config' => [
                    'protocol' => 'analytics',
                    'router_upstream' => [
                        'node_id' => $router->id,
                        'node' => $router->name,
                        'url' => 'http://10.6.0.1:80',
                    ],
                    'router_backend_pool' => [[
                        'node_id' => $backend->id,
                        'node' => $backend->name,
                        'url' => 'http://10.6.0.20:8000',
                    ]],
                    'upstreams' => [$upstream],
                    'tls' => [
                        'managed_by' => 'orbit',
                        'trusted_by_gateway_ca' => true,
                        'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                        'key_path' => '/etc/orbit/certs/analytics.orbit.key',
                    ],
                ],
            ]),
            'backend' => $backend,
        ];
    }

    if ($family === 'websocket') {
        $backend = Node::factory()
            ->withActiveRole('websocket')
            ->create([
                'name' => 'websocket-1',
                'wireguard_address' => '10.6.0.30',
            ]);
        $upstream = [
            'node_id' => $backend->id,
            'node' => $backend->name,
            'scheme' => 'https',
            'host' => '10.6.0.30',
            'backend_name' => '10.6.0.30',
            'port' => 8080,
            'url' => 'https://10.6.0.30:8080',
        ];

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $router->id,
                'domain' => 'websocket.orbit',
                'owner_type' => 'router',
                'kind' => 'proxy',
                'config' => [
                    'protocol' => 'websocket',
                    'router_upstream' => [
                        'node_id' => $router->id,
                        'node' => $router->name,
                        'url' => 'http://10.6.0.1:80',
                    ],
                    'router_backend_pool' => [[
                        'node_id' => $backend->id,
                        'node' => $backend->name,
                        'url' => 'https://10.6.0.30:8080',
                    ]],
                    'router_backend_tls' => [
                        'trusted_by_gateway_ca' => true,
                        'ca_path' => '/etc/orbit/ca/root.crt',
                    ],
                    'upstreams' => [$upstream],
                    'tls' => [
                        'managed_by' => 'internal',
                        'trusted_by_gateway_ca' => true,
                        'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                        'key_path' => '/etc/orbit/certs/websocket.orbit.key',
                    ],
                ],
            ]),
            'backend' => $backend,
        ];
    }

    if ($family === 's3-service') {
        $backend = Node::factory()->withActiveRole('s3')->create(['name' => 'storage-1']);
        NodeTool::factory()->create([
            'node_id' => $backend->id,
            'name' => 'seaweedfs',
            'expected_state' => 'installed',
            'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
        ]);

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $router->id,
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
            ]),
            'backend' => $backend,
        ];
    }

    if ($family === 'metrics') {
        $backend = Node::factory()->withActiveRole('metrics')->create(['name' => 'metrics-1']);

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $router->id,
                'domain' => MetricsServiceRoute::Domain,
                'owner_type' => 'router',
                'kind' => 'proxy',
                'config' => MetricsServiceRoute::config(),
            ]),
            'backend' => $backend,
        ];
    }

    if ($family === 's3-public') {
        $backend = Node::factory()->withActiveRole('s3')->create(['name' => 'storage-1']);
        NodeTool::factory()->create([
            'node_id' => $backend->id,
            'name' => 'seaweedfs',
            'expected_state' => 'installed',
            'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => ['objects.example.test']],
        ]);
        $ingress = Node::factory()->ingress()->create(['name' => 'ingress-1']);

        return [
            'route' => ProxyRoute::factory()->create([
                'node_id' => $ingress->id,
                'domain' => 'objects.example.test',
                'owner_type' => 's3',
                'kind' => 'proxy',
                'config' => [
                    'placement' => 'ingress',
                    'owner_name' => 'seaweedfs',
                    'protocol' => 's3',
                    'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
                    'router_upstream' => [
                        'node_id' => $router->id,
                        'node' => $router->name,
                        'url' => 'http://10.6.0.1:80',
                    ],
                    'tls' => [
                        'cert_path' => '/etc/orbit/certs/objects.example.test.crt',
                        'key_path' => '/etc/orbit/certs/objects.example.test.key',
                    ],
                ],
            ]),
            'backend' => null,
        ];
    }

    $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1']);

    return [
        'route' => ProxyRoute::factory()->create([
            'node_id' => $gateway->id,
            'domain' => GatewayLeafIdentity::DefaultBrowserHostname,
            'owner_type' => 'gateway',
            'kind' => 'internal',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => GatewayCaddyRouteRenderer::Upstream],
            ],
        ]),
        'backend' => null,
    ];
}

it('accepts every complete non-instance ownership family', function (string $family): void {
    $route = complete_non_instance_route($family)['route'];

    expect(app(NonInstanceProxyRouteOwnership::class)->matches($route))->toBeTrue();
})->with(['custom', 'tool', 'analytics', 'websocket', 's3-service', 'metrics', 's3-public', 'gateway']);

it('rejects unstable extra config for every non-instance ownership family', function (string $family): void {
    $route = complete_non_instance_route($family)['route'];
    $config = is_array($route->config) ? $route->config : [];
    $route->forceFill(['config' => [...$config, 'unexpected' => 'value']])->save();

    expect(app(NonInstanceProxyRouteOwnership::class)->matches($route))->toBeFalse();
})->with(['custom', 'tool', 'analytics', 'websocket', 's3-service', 'metrics', 's3-public', 'gateway']);

it('rejects router services whose backend role is not active', function (string $family): void {
    ['route' => $route, 'backend' => $backend] = complete_non_instance_route($family);
    $backend?->roleAssignments()->update(['status' => 'error']);

    expect(app(NonInstanceProxyRouteOwnership::class)->matches($route))->toBeFalse();
})->with(['analytics', 'websocket', 's3-service', 'metrics']);

it('rejects router services with the wrong backend identity or URL', function (string $family): void {
    $route = complete_non_instance_route($family)['route'];
    $config = is_array($route->config) ? $route->config : [];

    if ($family === 's3-service') {
        $config['target']['value'] = 'http://other.s3.orbit:8333';
        $config['upstreams'][0]['host'] = 'other.s3.orbit';
    } else {
        $config['router_backend_pool'][0]['node'] = 'other-node';
        $config['router_backend_pool'][0]['url'] = 'https://203.0.113.10:9999';
    }

    $route->forceFill(['config' => $config])->save();

    expect(app(NonInstanceProxyRouteOwnership::class)->matches($route))->toBeFalse();
})->with(['analytics', 'websocket', 's3-service']);

it('rejects a gateway route with the wrong reserved domain or upstream', function (string $field): void {
    $route = complete_non_instance_route('gateway')['route'];

    if ($field === 'domain') {
        $route->forceFill(['domain' => 'other.orbit'])->save();
    } else {
        $route->forceFill([
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://other-service:9000'],
            ],
        ])->save();
    }

    expect(app(NonInstanceProxyRouteOwnership::class)->matches($route))->toBeFalse();
})->with(['domain', 'upstream']);
