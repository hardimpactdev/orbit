<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Metrics\MetricsServiceRoute;
use App\Services\Proxy\NonInstanceProxyRouteOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('accepts every complete non-instance ownership family', function (): void {
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.1',
        ]);
    $ingress = Node::factory()->ingress()->create(['name' => 'ingress-1']);
    $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1']);
    $appHost = Node::factory()->appDev()->create(['name' => 'app-1']);
    $agent = Node::factory()
        ->agent()
        ->create([
            'name' => 'agent-1',
            'tld' => 'agent',
        ]);
    NodeTool::factory()->create([
        'node_id' => $agent->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
    ]);

    $routes = [
        ProxyRoute::factory()->create([
            'node_id' => $appHost->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]),
        ProxyRoute::factory()->create([
            'node_id' => $agent->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
            ],
        ]),
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'analytics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'analytics',
                'router_backend_pool' => [
                    ['node_id' => $appHost->id, 'node' => $appHost->name, 'url' => 'http://10.6.0.2:8000'],
                ],
            ],
        ]),
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'websocket',
                'router_backend_pool' => [
                    ['node_id' => $appHost->id, 'node' => $appHost->name, 'url' => 'https://10.6.0.2:8080'],
                ],
            ],
        ]),
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => MetricsServiceRoute::Domain,
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => MetricsServiceRoute::config(),
        ]),
        ProxyRoute::factory()->create([
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
        ProxyRoute::factory()->create([
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
        ProxyRoute::factory()->create([
            'node_id' => $gateway->id,
            'domain' => 'gateway.orbit',
            'owner_type' => 'gateway',
            'kind' => 'internal',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://orbit-gateway:80']],
        ]),
    ];

    $ownership = new NonInstanceProxyRouteOwnership;

    foreach ($routes as $route) {
        expect($ownership->matches($route))->toBeTrue();
    }
});
