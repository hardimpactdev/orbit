<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Project;
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
function appOwningNodeResolverApp(array $attributes = []): Project
{
    $app = Project::factory()->create($attributes);

    assert($app instanceof Project);

    return $app;
}

/** @param array<string, mixed> $attributes */
function appOwningNodeResolverUnsavedApp(array $attributes = []): Project
{
    $app = Project::factory()->make($attributes);

    assert($app instanceof Project);

    return $app;
}

it('resolves the loaded owning node for an app', function (): void {
    $node = appOwningNodeResolverNode();
    $app = appOwningNodeResolverApp(['name' => 'docs', 'node_id' => $node->id]);

    $resolved = new AppOwningNodeResolver()->resolve($app);

    expect($resolved->is($node))->toBeTrue();
});

it('throws when the app has no owning node', function (): void {
    $app = appOwningNodeResolverUnsavedApp([
        'name' => 'orphan',
        'node_id' => 99_999,
    ]);
    $app->setRelation('node', null);

    expect(fn () => new AppOwningNodeResolver()->resolve($app))
        ->toThrow(RuntimeException::class, "Project 'orphan' has no owning node.");
});
