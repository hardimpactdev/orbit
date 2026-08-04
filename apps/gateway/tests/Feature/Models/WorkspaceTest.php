<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores workspace registry intent and derives canonical fields', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
    ]);

    $app = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'php_version' => '8.5',
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature-docs',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
        'php_version' => null,
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    expect($workspace->app->is($app))
        ->toBeTrue()
        ->and($app->workspaces()->pluck('name')->all())
        ->toBe(['feature-docs'])
        ->and($workspace->effectivePhpVersion())
        ->toBe('8.5')
        ->and($workspace->url())
        ->toBe('https://feature-docs.docs.test')
        ->and($workspace->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending);
});

it('derives workspace url from a matching orbit app instance placement', function (): void {
    $beast = Node::factory()->appDev(['tld' => 'beast'])->create(['name' => 'Beast']);
    $nmbp = Node::factory()->appDev(['tld' => 'nmbp'])->create(['name' => 'NMBP']);

    $app = Project::factory()->for($beast, 'node')->create([
        'name' => 'happie',
        'path' => '/Users/nckrtl/apps/happie-beast',
        'domain' => null,
    ]);

    AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $beast->id,
            node: 'Beast',
            path: '/Users/nckrtl/apps/happie-beast',
            document_root: 'public',
            domain: null,
        ),
    ]);

    AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
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

    $app = Project::factory()->for($beast, 'node')->create([
        'name' => 'happie',
        'path' => '/home/nckrtl/apps/happie',
        'domain' => null,
    ]);

    AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $beast->id,
            node: 'beast',
            path: '/home/nckrtl/apps/happie',
            document_root: 'public',
            domain: null,
        ),
    ]);

    AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
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
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'source_hash' => str_repeat('c', 64),
    ]);

    expect($workspace->url())->toBe('https://recipe.happie.nmbp');
});

it('prefers an explicit workspace php version over the parent app version', function (): void {
    $app = Project::factory()->create([
        'php_version' => '8.5',
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'php_version' => '8.4',
    ]);

    expect($workspace->effectivePhpVersion())->toBe('8.4');
});

it('keeps workspace names unique within a parent app only', function (): void {
    $firstApp = Project::factory()->create();
    $secondApp = Project::factory()->create();

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
    $node = $workspace->app->node;

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
