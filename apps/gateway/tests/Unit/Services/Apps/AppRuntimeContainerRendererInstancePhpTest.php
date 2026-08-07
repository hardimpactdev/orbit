<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function snapshot_php_instance(App $app, Node $node, string $name, ?string $phpVersion): Instance
{
    return Instance::factory()->for($app)->create([
        'name' => $name,
        'php_version' => $phpVersion,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);
}

it('renders each instance with its own stored php version, not the app default', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->for($node, 'node')->create(['name' => 'docs', 'php_version' => '8.5']);
    $pinned = snapshot_php_instance($app, $node, 'production', '8.3');
    $current = snapshot_php_instance($app, $node, 'development', '8.5');

    $renderer = app(AppRuntimeContainerRenderer::class);

    expect($renderer->runtimeAppForInstance($app, $pinned)->php_version)
        ->toBe('8.3')
        ->and($renderer->runtimeAppForInstance($app, $current)->php_version)
        ->toBe('8.5');
});

it('does not move an instance when the app default changes', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->for($node, 'node')->create(['name' => 'docs', 'php_version' => '8.4']);
    $instance = snapshot_php_instance($app, $node, 'development', '8.4');

    $app->forceFill(['php_version' => '8.5'])->save();

    expect(app(AppRuntimeContainerRenderer::class)->runtimeAppForInstance($app->refresh(), $instance)->php_version)
        ->toBe('8.4');
});
