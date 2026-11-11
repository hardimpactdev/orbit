<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

/** A completed default before the fix: its seed and Route exist, but its projection mark still waits. */
function projectionRepairDefault(ProjectType $type = ProjectType::LaravelApp, string $name = 'default'): Instance
{
    $node = containerDefaultNode();
    $project = containerDefaultProject($type, $type->isWebServing() ? '8.5' : null);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => '/srv/workspace', 'status' => 'active']);
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Running, 'seed_commit' => str_repeat('d', 40)]);
    if ($type->isWebServing()) {
        $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
    }

    return $instance;
}

it('repairs a completed container default without changing its policy or seed, and runs again safely', function (ProjectType $type): void {
    $instance = projectionRepairDefault($type);
    $before = $instance->fresh()->getAttributes();
    $runtime = $instance->runtime()->firstOrFail()->getAttributes();
    // An older failed attempt does not invalidate a later completed create or migration.
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'failed', 'input' => []]);
    $migration = require database_path('migrations/2027_02_05_000001_clear_completed_container_default_projections.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect($instance->fresh()->getAttributes())->toBe([...$before, 'development_projection_pending' => 0]);
    expect($instance->runtime()->firstOrFail()->getAttributes())->toBe($runtime);
})->with(['web Route' => ProjectType::LaravelApp, 'non-web without Route' => ProjectType::NodePackage]);

it('preserves an incomplete or ambiguous projection', function (Closure $arrange): void {
    $instance = projectionRepairDefault();
    $route = $instance->authoritativeRoute();
    $arrange($instance, $route);
    $migration = require database_path('migrations/2027_02_05_000001_clear_completed_container_default_projections.php');

    $migration->up();

    $this->assertDatabaseHas('instances', ['id' => $instance->id, 'development_projection_pending' => true]);
})->with([
    'no container / restored host' => fn (Instance $instance) => $instance->runtime()->delete(),
    'seed not ready' => fn (Instance $instance) => $instance->runtime()->update(['seed_commit' => null]),
    'not active' => fn (Instance $instance) => $instance->update(['status' => 'source_resolved']),
    'inactive app-dev role' => fn (Instance $instance) => $instance->node->roles()->where('role', RoleName::AppDev)->update(['status' => 'removing']),
    'inactive Node' => fn (Instance $instance) => $instance->node->update(['status' => 'removing']),
    'activating Route' => fn (Instance $instance, Route $route) => $route->update(['status' => 'activating']),
    'failed publication' => fn (Instance $instance, Route $route) => $route->update(['target_set_step' => 'pending', 'failed_step' => 'publish', 'error_code' => 'caddy.failed']),
    'Route web root only' => fn (Instance $instance, Route $route) => DB::table('routes')->where('id', $route->id)->update(['web_root' => 'public']),
    'placement transition' => fn (Instance $instance, Route $route) => DB::table('routes')->where('id', $route->id)->update(['transition_node_id' => $instance->node_id]),
    'replacement cleanup' => fn (Instance $instance, Route $route) => DB::table('routes')->where('id', $route->id)->update(['replacement_step' => 'pending']),
    'target-set publication' => fn (Instance $instance, Route $route) => DB::table('routes')->where('id', $route->id)->update(['target_set_step' => 'pending']),
    'queued Route publication' => fn (Instance $instance, Route $route) => Operation::query()->create([
        'kind' => 'route.update', 'target_type' => 'route', 'target_id' => $route->id, 'node_id' => $instance->node_id, 'status' => 'queued', 'input' => ['mode' => 'publication']]),
    'waiting Route publication' => fn (Instance $instance, Route $route) => Operation::query()->create([
        'kind' => 'route.update', 'target_type' => 'route', 'target_id' => $route->id, 'node_id' => $instance->node_id, 'status' => 'waiting', 'input' => ['mode' => 'publication']]),
    'running Instance publication' => fn (Instance $instance) => Operation::query()->create([
        'kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => 'running', 'input' => []]),
    'retained hold of terminal publisher' => function (Instance $instance): void {
        $operation = Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => 'failed', 'input' => []]);
        DB::table('route_publication_holds')->insert(['node_id' => $instance->node_id, 'operation_id' => $operation->id, 'created_at' => now()]);
    },
]);

it('keeps a non-default container projection pending', function (): void {
    $instance = projectionRepairDefault(ProjectType::LaravelApp, 'feature');

    (require database_path('migrations/2027_02_05_000001_clear_completed_container_default_projections.php'))->up();

    $this->assertDatabaseHas('instances', ['id' => $instance->id, 'development_projection_pending' => true]);
});

it('keeps a web Project without a Route pending', function (): void {
    $instance = projectionRepairDefault(ProjectType::NodePackage);
    $instance->project->update(['type' => ProjectType::LaravelApp, 'php_version' => '8.5']);

    (require database_path('migrations/2027_02_05_000001_clear_completed_container_default_projections.php'))->up();

    $this->assertDatabaseHas('instances', ['id' => $instance->id, 'development_projection_pending' => true]);
});
