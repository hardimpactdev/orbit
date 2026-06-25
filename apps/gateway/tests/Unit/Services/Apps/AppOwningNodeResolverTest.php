<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppOwningNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('resolves the loaded owning node for an app', function (): void {
    $node = Node::factory()->create();
    $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

    $resolved = new AppOwningNodeResolver()->resolve($app);

    expect($resolved->is($node))->toBeTrue();
});

it('throws when the app has no owning node', function (): void {
    $app = App::factory()->make([
        'name' => 'orphan',
        'node_id' => 99_999,
    ]);
    $app->setRelation('node', null);

    expect(fn () => new AppOwningNodeResolver()->resolve($app))
        ->toThrow(RuntimeException::class, "App 'orphan' has no owning node.");
});
