<?php

declare(strict_types=1);

use App\Exceptions\ProxyRouteOwnerInvariantViolation;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a valid instance-backed app route', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->for($app)->create();

    $route = ProxyRoute::factory()->forApp($instance, $app)->create();

    expect($route->owner_type)
        ->toBe('app')
        ->and($route->instance_id)
        ->toBe($instance->id)
        ->and($route->app_id)
        ->toBe($app->id)
        ->and($route->workspace_id)
        ->toBeNull();
});

it('persists a valid workspace-owned route', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->for($app)->create();
    $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);

    $route = ProxyRoute::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'workspace_id' => $workspace->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'config' => [],
    ]);

    expect($route->owner_type)
        ->toBe('workspace')
        ->and($route->instance_id)
        ->toBe($instance->id)
        ->and($route->workspace_id)
        ->toBe($workspace->id);
});

it('persists a valid non-instance custom route with no ownership foreign keys', function (): void {
    $route = ProxyRoute::factory()->create([
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['upstream' => 'http://127.0.0.1:8080'],
    ]);

    expect($route->owner_type)
        ->toBe('custom')
        ->and($route->instance_id)
        ->toBeNull()
        ->and($route->app_id)
        ->toBeNull()
        ->and($route->workspace_id)
        ->toBeNull();
});

it('rejects contradictory proxy route owners', function (string $name, Closure $factory): void {
    expect($factory)->toThrow(ProxyRouteOwnerInvariantViolation::class);
})->with([
    'app-missing-instance' => [
        'app-missing-instance',
        function (): void {
            $app = App::factory()->create();
            save_proxy_route_without_factory([
                'app_id' => $app->id,
                'workspace_id' => null,
                'instance_id' => null,
                'owner_type' => 'app',
                'kind' => 'app',
            ]);
        },
    ],
    'app-wrong-kind' => [
        'app-wrong-kind',
        function (): void {
            $app = App::factory()->create();
            $instance = Instance::factory()->for($app)->create();
            ProxyRoute::factory()->create([
                'app_id' => $app->id,
                'instance_id' => $instance->id,
                'owner_type' => 'app',
                'kind' => 'proxy',
                'config' => [],
            ]);
        },
    ],
    'app-conflicting-app_id' => [
        'app-conflicting-app_id',
        function (): void {
            $app = App::factory()->create();
            $other = App::factory()->create();
            $instance = Instance::factory()->for($app)->create();
            save_proxy_route_without_factory([
                'app_id' => $other->id,
                'workspace_id' => null,
                'instance_id' => $instance->id,
                'owner_type' => 'app',
                'kind' => 'app',
            ]);
        },
    ],
    'app-with-workspace' => [
        'app-with-workspace',
        function (): void {
            $app = App::factory()->create();
            $instance = Instance::factory()->for($app)->create();
            $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
            ProxyRoute::factory()->create([
                'app_id' => $app->id,
                'instance_id' => $instance->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [],
            ]);
        },
    ],
    'custom-with-instance_id' => [
        'custom-with-instance_id',
        function (): void {
            $instance = Instance::factory()->create();
            ProxyRoute::factory()->create([
                'instance_id' => $instance->id,
                'owner_type' => 'custom',
                'kind' => 'proxy',
                'config' => ['upstream' => 'http://127.0.0.1:8080'],
            ]);
        },
    ],
    'custom-with-app_id' => [
        'custom-with-app_id',
        function (): void {
            $app = App::factory()->create();
            ProxyRoute::factory()->create([
                'app_id' => $app->id,
                'owner_type' => 'custom',
                'kind' => 'proxy',
                'config' => ['upstream' => 'http://127.0.0.1:8080'],
            ]);
        },
    ],
    'workspace-missing-workspace' => [
        'workspace-missing-workspace',
        function (): void {
            $app = App::factory()->create();
            $instance = Instance::factory()->for($app)->create();
            ProxyRoute::factory()->create([
                'app_id' => $app->id,
                'instance_id' => $instance->id,
                'workspace_id' => null,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => [],
            ]);
        },
    ],
    'unknown-owner-type' => [
        'unknown-owner-type',
        function (): void {
            ProxyRoute::factory()->create([
                'owner_type' => 'legacy',
                'kind' => 'proxy',
                'config' => ['upstream' => 'http://127.0.0.1:8080'],
            ]);
        },
    ],
    'public-instance-projection' => [
        'public-instance-projection',
        function (): void {
            ProxyRoute::factory()->create([
                'owner_type' => 'instance',
                'kind' => 'proxy',
                'config' => ['upstream' => 'http://127.0.0.1:8080'],
            ]);
        },
    ],
    'legacy-invalid-row-cannot-resave' => [
        'legacy-invalid-row-cannot-resave',
        function (): void {
            $route = persist_proxy_route_bypassing_owner_guard([
                'node_id' => Node::factory()->create()->id,
                'domain' => 'legacy.test',
                'app_id' => null,
                'workspace_id' => null,
                'instance_id' => null,
                'owner_type' => 'legacy',
                'kind' => 'proxy',
                'source_hash' => str_repeat('a', 64),
                'config' => ['upstream' => 'http://127.0.0.1:8080'],
            ]);
            $route->touch();
        },
    ],
]);

/**
 * @param  array<string, mixed>  $attributes
 */
function save_proxy_route_without_factory(array $attributes): ProxyRoute
{
    $route = new ProxyRoute([
        'node_id' => Node::factory()->create()->id,
        'domain' => fake()->unique()->bothify('route-####.test'),
        'source_hash' => str_repeat('a', 64),
        'config' => [],
        ...$attributes,
    ]);
    $route->save();

    return $route;
}
