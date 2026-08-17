<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Instance;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires explicit instance ownership for an instance-backed generic route', function (): void {
    $app = App::factory()->create();

    expect(fn (): ProxyRoute => ProxyRoute::factory()->create([
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
    ]))
        ->toThrow(
            \RuntimeException::class,
            'ProxyRoute factory requires an explicit instance_id for owner_type=app.',
        );
});

it('does not create ownership when forApp receives no concrete instance', function (): void {
    expect(fn (): ProxyRoute => ProxyRoute::factory()->forApp()->create())
        ->toThrow(ArgumentCountError::class)
        ->and(App::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(0);
});

it('does not select an App sole Instance in place of a concrete owner', function (): void {
    $app = App::factory()->create();
    Instance::factory()->for($app)->create(['name' => 'development']);

    expect(fn (): ProxyRoute => ProxyRoute::factory()->forApp($app)->create())
        ->toThrow(TypeError::class)
        ->and(ProxyRoute::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(1);
});

it('creates an instance-backed route when the owner is explicit', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->for($app)->create();
    $route = ProxyRoute::factory()->forApp($instance, $app)->create();

    expect($route->app_id)
        ->toBe($app->id)
        ->and($route->instance_id)
        ->toBe($instance->id);
});

it('rejects a compatibility App that conflicts with the concrete Instance', function (): void {
    $app = App::factory()->create();
    $otherApp = App::factory()->create();
    $instance = Instance::factory()->for($app)->create();

    expect(fn (): ProxyRoute => ProxyRoute::factory()->forApp($instance, $otherApp)->create())
        ->toThrow(
            RuntimeException::class,
            'ProxyRoute factory forApp state received an Instance owned by another App.',
        );
});
