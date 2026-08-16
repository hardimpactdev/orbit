<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Tools\ToolAppNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the serving node from the selected project instance', function (): void {
    $developmentNode = Node::factory()->appDev()->create(['name' => 'dev-1']);
    $productionNode = Node::factory()->appProd()->create(['name' => 'prod-1']);
    $app = App::factory()->create(['name' => 'docs']);

    Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $developmentNode->id,
            node: $developmentNode->name,
            path: '/srv/docs-dev',
            document_root: 'public',
        ),
    ]);
    Instance::factory()->for($app, 'app')->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            node: $productionNode->name,
            path: '/srv/docs',
            document_root: 'public',
        ),
    ]);

    expect(app(ToolAppNodeResolver::class)->resolve('docs.production')?->is($productionNode))
        ->toBeTrue()
        ->and(app(ToolAppNodeResolver::class)->resolve('docs'))
        ->toBeNull();
});
