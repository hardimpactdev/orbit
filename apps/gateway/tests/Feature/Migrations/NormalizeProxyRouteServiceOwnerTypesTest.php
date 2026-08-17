<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes only complete legacy service and public S3 ownership tuples', function (): void {
    $node = Node::factory()->router()->create();
    $websocket = ProxyRoute::query()->create([
        'node_id' => $node->id,
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
        'node_id' => $node->id,
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
        'node_id' => $node->id,
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
