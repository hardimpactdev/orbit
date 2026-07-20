<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceEnvApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies values only to the selected workspace path', function (): void {
    $node = Node::factory()->gateway()->create(['status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    $app = Project::factory()->for($node, 'node')->create([
        'runtime' => AppRuntimeKind::Static,
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            domain: $app->domain,
        ),
    ]);
    $path = storage_path('framework/testing/workspace-env-applier');
    File::ensureDirectoryExists($path);
    File::put($path.'/.env', "APP_ENV=local\n");
    $workspace = Workspace::factory()
        ->for($app)
        ->for($instance, 'appInstance')
        ->create(['path' => $path]);

    $result = app(WorkspaceEnvApplier::class)->apply($workspace, [
        'APP_ENV' => 'production',
    ]);

    expect($result->envPath)
        ->toBe($path.'/.env')
        ->and(File::get($path.'/.env'))
        ->toBe("APP_ENV=production\n");
});
