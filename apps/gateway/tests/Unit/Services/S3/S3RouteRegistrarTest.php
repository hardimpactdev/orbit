<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\S3\S3RouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @group service
 */
function s3AssignRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

it('registers router-owned s3 service route to one rustfs backend', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'gateway');
    s3AssignRole($router, 'vpn');
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();

    $route = ProxyRoute::query()->where('domain', 's3.orbit')->firstOrFail();

    expect($route)
        ->node_id->toBe($router->id)
        ->owner_type->toBe('tool')
        ->kind->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'rustfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:9000'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 9000],
            ],
        ]);
})->group('service');

it('stores the pool shape even for a single rustfs backend', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();

    $route = ProxyRoute::query()->where('domain', 's3.orbit')->firstOrFail();

    expect($route->config['upstreams'])->toHaveCount(1)
        ->and($route->config['upstreams'][0])->toBe([
            'scheme' => 'http',
            'host' => 'storage-1.s3.orbit',
            'port' => 9000,
        ]);
})->group('service');

it('fails clearly when there is no active router node', function (): void {
    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The S3 service route requires an active router node.')
    ->group('service');

it('fails clearly when there are no active s3 nodes', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    app(S3RouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The S3 service route requires at least one active s3 backend.')
    ->group('service');

it('fails clearly when s3 node has no rustfs tool row', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');

    app(S3RouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The S3 service route requires at least one active rustfs tool row.')
    ->group('service');

it('updates the service route when called again', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();
    app(S3RouteRegistrar::class)->syncServiceRoute();

    expect(ProxyRoute::query()->where('domain', 's3.orbit')->count())->toBe(1);
})->group('service');

it('syncs public s3 host as ingress route forwarding to s3.orbit', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();

    expect($route)
        ->node_id->toBe($edge->id)
        ->owner_type->toBe('tool')
        ->kind->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'rustfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
        ]);
})->group('service');

it('skips ingress route sync when there are no public hosts', function (): void {
    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    expect(ProxyRoute::query()->where('owner_type', 'tool')->count())->toBe(0);
})->group('service');

it('removes the public host route when owner_type is tool and owner_name is rustfs', function (): void {
    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => ['s3.example.com']],
    ]);

    ProxyRoute::factory()->create([
        'domain' => 's3.example.com',
        'node_id' => $edge->id,
        'owner_type' => 'tool',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'rustfs', 'protocol' => 's3', 'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit']],
    ]);

    app(S3RouteRegistrar::class)->removePublicHost($tool, 's3.example.com');

    expect(ProxyRoute::query()->where('domain', 's3.example.com')->exists())->toBeFalse();
})->group('service');
