<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('normalizes legacy metrics route upstream intent for Caddy host access', function (): void {
    $migrationPath = database_path('migrations/2026_06_17_010000_normalize_metrics_route_upstream.php');

    expect(is_file($migrationPath))->toBeTrue();

    $node = Node::factory()->router()->create(['name' => 'gateway']);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'metrics',
        'status' => 'active',
    ]);
    $routeId = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => null,
        'workspace_id' => null,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://gateway.metrics.orbit:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require $migrationPath;
    $migration->up();

    $route = ProxyRoute::query()->findOrFail($routeId);

    expect($route->config)
        ->toMatchArray([
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://host.docker.internal:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'host.docker.internal', 'port' => 3000],
            ],
        ])
        ->and($route->source_hash)
        ->toBe(app(ProxyRouteRenderer::class)->sourceHash($route));
});

it('does not normalize malformed metrics route ownership', function (array $attributes): void {
    $migrationPath = database_path('migrations/2026_06_17_010000_normalize_metrics_route_upstream.php');
    $node = Node::factory()->router()->create(['name' => 'gateway']);
    $config = [
        'owner_name' => 'grafana',
        'protocol' => 'http',
        'target' => ['type' => 'upstream', 'value' => 'http://gateway.metrics.orbit:3000'],
        'upstreams' => [['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000]],
    ];
    $routeConfig = $attributes['config'] ?? $config;
    $route = ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'metrics.orbit',
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => str_repeat('0', 64),
        'config' => $routeConfig,
        ...$attributes,
    ]);

    $migration = require $migrationPath;
    $migration->up();

    expect($route->fresh()?->config)
        ->toBe($routeConfig)
        ->and($route->fresh()?->source_hash)
        ->toBe(str_repeat('0', 64));
})->with([
    'stray app identity' => [fn (): array => ['app_id' => \App\Models\App::factory()->create()->id]],
    'stray workspace identity' => [fn (): array => ['workspace_id' => \App\Models\Workspace::factory()->create()->id]],
    'stray instance identity' => [fn (): array => ['instance_id' => \App\Models\Instance::factory()->create()->id]],
    'wrong stable target' => [[
        'config' => [
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => ['type' => 'upstream', 'value' => 'http://unrelated.test:3000'],
            'upstreams' => [['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000]],
        ],
    ]],
    'incomplete stable upstreams' => [[
        'config' => [
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => ['type' => 'upstream', 'value' => 'http://gateway.metrics.orbit:3000'],
            'upstreams' => [],
        ],
    ]],
    'extra stable config' => [[
        'config' => [
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => ['type' => 'upstream', 'value' => 'http://gateway.metrics.orbit:3000'],
            'upstreams' => [['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000]],
            'unexpected' => true,
        ],
    ]],
]);

it('does not normalize metrics intent on a non-canonical router node', function (): void {
    Node::factory()->router()->create(['name' => 'canonical-router']);
    $otherRouter = Node::factory()->router()->create(['name' => 'other-router']);
    $config = [
        'owner_name' => 'grafana',
        'protocol' => 'http',
        'target' => ['type' => 'upstream', 'value' => 'http://gateway.metrics.orbit:3000'],
        'upstreams' => [['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000]],
    ];
    $route = ProxyRoute::query()->create([
        'node_id' => $otherRouter->id,
        'domain' => 'metrics.orbit',
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => str_repeat('0', 64),
        'config' => $config,
    ]);

    $migration = require database_path('migrations/2026_06_17_010000_normalize_metrics_route_upstream.php');
    $migration->up();

    expect($route->fresh()?->config)
        ->toBe($config)
        ->and($route->fresh()?->source_hash)
        ->toBe(str_repeat('0', 64));
});
