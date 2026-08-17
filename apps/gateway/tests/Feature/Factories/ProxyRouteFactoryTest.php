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

it('rejects an ambiguous forApp state unless the instance is explicit', function (): void {
    $app = App::factory()->create();
    Instance::factory()->for($app)->create(['name' => 'development']);
    Instance::factory()->for($app)->create(['name' => 'production']);

    expect(fn (): ProxyRoute => ProxyRoute::factory()->forApp($app)->create())
        ->toThrow(
            \RuntimeException::class,
            'ProxyRoute factory forApp state requires an explicit Instance when the App has multiple instances.',
        );
});

it('creates an instance-backed route when the owner is explicit', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->for($app)->create();
    $route = ProxyRoute::factory()->forApp($app, $instance)->create();

    expect($route->app_id)
        ->toBe($app->id)
        ->and($route->instance_id)
        ->toBe($instance->id);
});
