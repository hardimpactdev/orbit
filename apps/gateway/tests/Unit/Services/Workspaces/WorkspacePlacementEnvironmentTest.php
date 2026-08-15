<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Instance is the placement authority: environment is derived from the
 * instance's serving node role and its own domain, mirroring
 * AppRegistrar::registrationEnvironment rather than reading an app column.
 */
it('treats a missing domain as development', function (): void {
    $node = Node::factory()->appDev()->create();

    expect(new WorkspacePlacement()->environmentFor(null, $node))
        ->toBe('development')
        ->and(new WorkspacePlacement()->environmentFor('', $node))
        ->toBe('development');
});

it('treats a node-tld domain on an app-dev node as development', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);

    expect(new WorkspacePlacement()->environmentFor('myapp.test', $node))->toBe('development');
});

it('treats a custom domain as production even on an app-dev node', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);

    expect(new WorkspacePlacement()->environmentFor('myapp.example.com', $node))->toBe('production');
});

it('treats any domain on a node without the app-dev role as production', function (): void {
    $node = Node::factory()->appProd()->create(['tld' => 'test']);

    expect(new WorkspacePlacement()->environmentFor('myapp.test', $node))->toBe('production');
});

it('treats a domain with no resolvable node as production', function (): void {
    expect(new WorkspacePlacement()->environmentFor('myapp.test', null))->toBe('production');
});

it('derives development for a dev instance from its driver config domain and node', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->create();
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/myapp',
            document_root: 'public',
            domain: "myapp.{$node->tld}",
        ),
    ]);

    expect(new WorkspacePlacement()->environmentForInstance($instance))->toBe('development');
});

it('derives production for an instance with a custom domain', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->create();
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/myapp',
            document_root: 'public',
            domain: 'myapp.example.com',
        ),
    ]);

    expect(new WorkspacePlacement()->environmentForInstance($instance))->toBe('production');
});

it('derives development for an instance with no domain', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->create();
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/myapp',
            document_root: 'public',
            domain: null,
        ),
    ]);

    expect(new WorkspacePlacement()->environmentForInstance($instance))->toBe('development');
});
