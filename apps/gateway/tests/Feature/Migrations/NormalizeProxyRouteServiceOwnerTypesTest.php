<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes only complete legacy service and public S3 ownership tuples', function (): void {
    $router = Node::factory()->router()->create();
    $ingress = Node::factory()->ingress()->create();
    $websocket = ProxyRoute::query()->create([
        'node_id' => $router->id,
        'domain' => 'websocket.orbit',
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'websocket',
        'kind' => 'proxy',
        'source_hash' => str_repeat('a', 64),
        'config' => ['protocol' => 'websocket'],
    ]);
    $service = ProxyRoute::query()->create([
        'node_id' => $router->id,
        'domain' => 's3.orbit',
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'tool',
        'kind' => 'proxy',
        'source_hash' => str_repeat('b', 64),
        'config' => ['owner_name' => 'rustfs', 'protocol' => 's3'],
    ]);
    $public = ProxyRoute::query()->create([
        'node_id' => $ingress->id,
        'domain' => 'objects.example.com',
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'tool',
        'kind' => 'proxy',
        'source_hash' => str_repeat('c', 64),
        'config' => ['owner_name' => 'rustfs', 'protocol' => 's3'],
    ]);

    $migration = require database_path('migrations/2026_06_10_000002_normalize_proxy_route_service_owner_types.php');
    $migration->up();

    expect($websocket->fresh()?->owner_type)
        ->toBe('router')
        ->and($service->fresh()?->owner_type)
        ->toBe('router')
        ->and($public->fresh()?->owner_type)
        ->toBe('s3');
});

it('preserves legacy owner types on wrong or non-canonical family nodes in both directions', function (): void {
    $canonicalRouter = Node::factory()->router()->create();
    $otherRouter = Node::factory()->router()->create();
    $canonicalIngress = Node::factory()->ingress()->create();
    $otherIngress = Node::factory()->ingress()->create();

    $upRoutes = collect([
        ProxyRoute::query()->create([
            'node_id' => $otherRouter->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'websocket',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => ['protocol' => 'websocket'],
        ]),
        ProxyRoute::query()->create([
            'node_id' => $canonicalIngress->id,
            'domain' => 's3.orbit',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => ['owner_name' => 'rustfs', 'protocol' => 's3'],
        ]),
        ProxyRoute::query()->create([
            'node_id' => $otherIngress->id,
            'domain' => 'objects.example.com',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('c', 64),
            'config' => ['owner_name' => 'rustfs', 'protocol' => 's3'],
        ]),
    ]);

    $migration = require database_path('migrations/2026_06_10_000002_normalize_proxy_route_service_owner_types.php');
    $migration->up();

    expect($upRoutes->map(fn (ProxyRoute $route): string => $route->fresh()->owner_type)->all())
        ->toBe(['websocket', 'tool', 'tool']);

    $upRoutes->each(fn (ProxyRoute $route): ?bool => $route->delete());

    $downRoutes = collect([
        ProxyRoute::query()->create([
            'node_id' => $otherRouter->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('d', 64),
            'config' => ['protocol' => 'websocket'],
        ]),
        ProxyRoute::query()->create([
            'node_id' => $canonicalIngress->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('e', 64),
            'config' => ['owner_name' => 'rustfs', 'protocol' => 's3'],
        ]),
        ProxyRoute::query()->create([
            'node_id' => $otherIngress->id,
            'domain' => 'legacy-objects.example.com',
            'owner_type' => 's3',
            'kind' => 'proxy',
            'source_hash' => str_repeat('f', 64),
            'config' => ['owner_name' => 'rustfs', 'protocol' => 's3'],
        ]),
    ]);

    $migration->down();

    expect($downRoutes->map(fn (ProxyRoute $route): string => $route->fresh()->owner_type)->all())
        ->toBe(['router', 'router', 's3'])
        ->and($canonicalRouter->exists)
        ->toBeTrue();
});

it('preserves malformed legacy service ownership tuples', function (array $routeAttributes): void {
    $node = Node::factory()->router()->create();
    $route = ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'websocket.orbit',
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'websocket',
        'kind' => 'proxy',
        'source_hash' => str_repeat('a', 64),
        'config' => ['protocol' => 'websocket'],
        ...$routeAttributes,
    ]);

    $migration = require database_path('migrations/2026_06_10_000002_normalize_proxy_route_service_owner_types.php');
    $migration->up();

    expect($route->fresh()?->owner_type)->toBe('websocket');
})->with([
    'stray app identity' => [fn (): array => ['app_id' => \App\Models\App::factory()->create()->id]],
    'stray workspace identity' => [fn (): array => ['workspace_id' => \App\Models\Workspace::factory()->create()->id]],
    'stray instance identity' => [fn (): array => ['instance_id' => \App\Models\Instance::factory()->create()->id]],
    'wrong kind' => [['kind' => 'redirect']],
    'wrong family identity' => [['config' => ['protocol' => 'analytics']]],
]);
