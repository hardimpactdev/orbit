<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores workspace registry intent and derives canonical fields', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
    ]);

    $app = App::factory()
        ->placedOn($node)
        ->create([
            'name' => 'docs',
            'php_version' => '8.5',
        ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $app->instances()->firstOrFail()->id,
        'name' => 'feature-docs',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
        'php_version' => null,
        'adopted' => true,
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    expect(Schema::hasColumn('workspaces', 'adopted'))
        ->toBeTrue()
        ->and($workspace->app->is($app))
        ->toBeTrue()
        ->and($app->workspaces()->pluck('name')->all())
        ->toBe(['feature-docs'])
        ->and($workspace->effectivePhpVersion())
        ->toBe('8.5')
        ->and($workspace->url())
        ->toBe('https://feature-docs.docs.test')
        ->and($workspace->adopted)
        ->toBeTrue()
        ->and($workspace->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending);
});

it('derives workspace url from a matching orbit app instance placement', function (): void {
    $beast = Node::factory()->appDev(['tld' => 'beast'])->create(['name' => 'Beast']);
    $nmbp = Node::factory()->appDev(['tld' => 'nmbp'])->create(['name' => 'NMBP']);

    $app = App::factory()->create([
        'name' => 'happie',
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $beast->id,
            node: 'Beast',
            path: '/Users/nckrtl/apps/happie-beast',
            document_root: 'public',
            domain: null,
        ),
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'recipe',
        'path' => '/Users/nckrtl/apps/happie/workspaces/recipe',
    ]);

    expect($workspace->url())->toBe('https://recipe.happie.beast');
});

it('derives workspace url from explicit workspace proxy route before path placement', function (): void {
    $beast = Node::factory()->appDev(['tld' => 'test'])->create(['name' => 'beast']);
    $nmbp = Node::factory()->appDev(['tld' => 'nmbp'])->create(['name' => 'NMBP']);

    $app = App::factory()->create([
        'name' => 'happie',
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $beast->id,
            node: 'beast',
            path: '/home/nckrtl/apps/happie',
            document_root: 'public',
            domain: null,
        ),
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'recipe',
        'path' => '/Users/nckrtl/.codex/worktrees/9891/happie',
    ]);

    ProxyRoute::query()->create([
        'node_id' => $nmbp->id,
        'domain' => 'recipe.happie.nmbp',
        'app_id' => $app->id,
        'workspace_id' => $workspace->id,
        'instance_id' => $workspace->instance_id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'source_hash' => str_repeat('c', 64),
    ]);

    expect($workspace->url())->toBe('https://recipe.happie.nmbp');
});

it('ignores a workspace proxy route without persisted instance ownership', function (): void {
    $node = Node::factory()->appDev(['tld' => 'beast'])->create(['name' => 'beast']);
    $app = App::factory()->placedOn($node)->create(['name' => 'happie']);
    $workspace = Workspace::factory()->for($app)->create(['name' => 'recipe']);

    ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'unowned.example',
        'app_id' => $app->id,
        'workspace_id' => $workspace->id,
        'instance_id' => null,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'source_hash' => str_repeat('c', 64),
    ]);

    expect($workspace->url())->toBe('https://recipe.happie.beast');
});

it('ignores a workspace proxy route with conflicting instance ownership', function (): void {
    $node = Node::factory()->appDev(['tld' => 'beast'])->create(['name' => 'beast']);
    $app = App::factory()->placedOn($node)->create(['name' => 'happie']);
    $workspace = Workspace::factory()->for($app)->create(['name' => 'recipe']);
    $conflictingInstance = Instance::factory()->for($app)->create(['name' => 'preview']);

    ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'conflicting.example',
        'app_id' => $app->id,
        'workspace_id' => $workspace->id,
        'instance_id' => $conflictingInstance->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'source_hash' => str_repeat('c', 64),
    ]);

    expect($workspace->url())->toBe('https://recipe.happie.beast');
});

it('prefers an explicit workspace php version over the parent app version', function (): void {
    $app = App::factory()->create([
        'php_version' => '8.5',
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'php_version' => '8.4',
    ]);

    expect($workspace->effectivePhpVersion())->toBe('8.4');
});

it('keeps workspace names unique within a parent app only', function (): void {
    $firstApp = App::factory()->create();
    $secondApp = App::factory()->create();

    Workspace::factory()->create([
        'app_id' => $firstApp->id,
        'name' => 'feature-docs',
    ]);

    Workspace::factory()->create([
        'app_id' => $secondApp->id,
        'name' => 'feature-docs',
    ]);

    expect(Workspace::query()->where('name', 'feature-docs')->count())->toBe(2);

    expect(fn () => Workspace::factory()->create([
        'app_id' => $firstApp->id,
        'name' => 'feature-docs',
    ]))
        ->toThrow(QueryException::class);
});

it('keeps durable run history when step definitions are removed', function (): void {
    $workspace = Workspace::factory()->create();
    $step = WorkspaceStep::factory()->create([
        'app_id' => $workspace->app_id,
    ]);
    $run = WorkspaceRun::factory()->create([
        'workspace_id' => $workspace->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'status' => 'succeeded',
        'step_set_hash' => str_repeat('a', 64),
    ]);
    $runStep = WorkspaceRunStep::factory()->create([
        'workspace_run_id' => $run->id,
        'workspace_step_id' => $step->id,
        'command' => $step->command,
        'exit_code' => 0,
        'output' => 'ok',
    ]);

    $step->delete();
    $runStep->refresh();

    expect($workspace->runs()->first()->is($run))
        ->toBeTrue()
        ->and($run->runSteps()->first()->is($runStep))
        ->toBeTrue()
        ->and($run->phase)
        ->toBe(WorkspaceLifecyclePhase::Setup)
        ->and($runStep->workspace_step_id)
        ->toBeNull()
        ->and($runStep->step)
        ->toBeNull();
});

it('allows proxy routes to point at workspace-owned intent', function (): void {
    $workspace = Workspace::factory()->create();
    $node = app(WorkspacePlacement::class)->nodeForWorkspace($workspace);

    $route = ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'feature-docs.docs.test',
        'app_id' => $workspace->app_id,
        'workspace_id' => $workspace->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'source_hash' => str_repeat('b', 64),
    ]);

    expect($route->workspace->is($workspace))
        ->toBeTrue()
        ->and($workspace->proxyRoutes()->first()->is($route))
        ->toBeTrue();
});
