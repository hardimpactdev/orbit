<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Tools\ToolAppNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the serving node from the selected project instance', function (): void {
    $developmentNode = Node::factory()->appDev()->create(['name' => 'dev-1']);
    $productionNode = Node::factory()->appProd()->create(['name' => 'prod-1']);
    $project = Project::factory()->for($developmentNode, 'node')->create(['name' => 'docs']);

    AppInstance::factory()->for($project, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $developmentNode->id,
            node: $developmentNode->name,
            path: '/srv/docs-dev',
            document_root: 'public',
        ),
    ]);
    AppInstance::factory()->for($project, 'app')->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
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
