<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not derive runtime-unit aliases for workspaces whose placement is unresolved', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $development = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
    ]);
    $unresolvedInstance = AppInstance::factory()->for($app)->create([
        'name' => 'legacy',
        'driver_config' => new OrbitAppInstanceDriverConfigData,
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'app_instance_id' => $unresolvedInstance->id,
        'name' => 'legacy-workspace',
    ]);
    $process = OrbitProcess::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $development->id,
            'name' => 'vite',
        ]);

    $runtimeUnits = app(ProcessRuntimeUnitPayload::class)->forProcess(
        app: $app,
        process: $process,
        workspaceContext: $workspace,
    );

    expect($runtimeUnits)->toBe([]);
});
