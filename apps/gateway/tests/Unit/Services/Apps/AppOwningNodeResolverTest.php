<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppOwningNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function appOwningNodeResolverNode(): Node
{
    $node = Node::factory()->create();

    assert($node instanceof Node);

    return $node;
}

/** @param array<string, mixed> $attributes */
function appOwningNodeResolverApp(array $attributes = []): App
{
    $app = App::factory()->create($attributes);

    assert($app instanceof App);

    return $app;
}

/** @param array<string, mixed> $attributes */
function appOwningNodeResolverUnsavedApp(array $attributes = []): App
{
    $app = App::factory()->make($attributes);

    assert($app instanceof App);

    return $app;
}

it('resolves the loaded owning node for an app', function (): void {
    $node = appOwningNodeResolverNode();
    $app = appOwningNodeResolverApp(['name' => 'docs']);
    Instance::factory()->for($app, 'app')->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
        ),
    ]);

    $resolved = new AppOwningNodeResolver()->resolve($app);

    expect($resolved->is($node))->toBeTrue();
});

it('throws when the app has no owning node', function (): void {
    $app = appOwningNodeResolverUnsavedApp([
        'name' => 'orphan',
    ]);

    expect(fn () => new AppOwningNodeResolver()->resolve($app))
        ->toThrow(RuntimeException::class, "App 'orphan' has no owning node.");
});
