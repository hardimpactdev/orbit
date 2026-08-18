<?php

declare(strict_types=1);

use App\Actions\Workspaces\AddWorkspaceStep;
use App\Actions\Workspaces\RemoveWorkspaceStep;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('orders workspace setup and teardown steps independently', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $addStep = app(AddWorkspaceStep::class);
    $removeStep = app(RemoveWorkspaceStep::class);

    $first = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        instanceId: $instance->id,
        command: 'composer install',
    );
    $second = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        instanceId: $instance->id,
        command: 'php artisan migrate',
    );
    $inserted = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        instanceId: $instance->id,
        command: 'npm run build',
        beforeStepId: $second->id,
    );
    $teardown = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Teardown,
        instanceId: $instance->id,
        command: 'php artisan down',
    );

    expect(
        WorkspaceStep::query()
            ->where('instance_id', $instance->id)
            ->where('phase', WorkspaceLifecyclePhase::Setup)
            ->orderBy('sort_order')
            ->pluck('command')
            ->all(),
    )
        ->toBe([
            'composer install',
            'npm run build',
            'php artisan migrate',
        ])
        ->and($teardown->sort_order)
        ->toBe(1)
        ->and($first->timeoutSeconds())
        ->toBe(WorkspaceStep::DEFAULT_TIMEOUT_SECONDS);

    $removeStep->handle($inserted);

    expect(
        WorkspaceStep::query()
            ->where('instance_id', $instance->id)
            ->where('phase', WorkspaceLifecyclePhase::Setup)
            ->orderBy('sort_order')
            ->pluck('sort_order', 'command')
            ->all(),
    )->toBe([
        'composer install' => 1,
        'php artisan migrate' => 2,
    ]);
});

it('orders workspace steps independently per app instance', function (): void {
    $app = App::factory()->create();
    $nmbp = Instance::factory()->create(['app_id' => $app->id, 'name' => 'nmbp']);
    $development = Instance::factory()->create(['app_id' => $app->id, 'name' => 'development']);
    $addStep = app(AddWorkspaceStep::class);

    $nmbpStep = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'composer install',
        instanceId: $nmbp->id,
    );
    $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'npm install',
        instanceId: $development->id,
    );

    expect(WorkspaceStep::query()->where('instance_id', $nmbp->id)->pluck('command')->all())
        ->toBe(['composer install'])
        ->and(WorkspaceStep::query()->where('instance_id', $development->id)->pluck('command')->all())
        ->toBe(['npm install'])
        ->and($nmbpStep->instance_id)
        ->toBe($nmbp->id);
});

it('inserts workspace steps against the current schema without an app_id compat branch', function (): void {
    expect(file_get_contents(app_path('Actions/Workspaces/AddWorkspaceStep.php')))
        ->not->toContain('Schema::hasColumn')->and(file_get_contents(database_path(
            'factories/WorkspaceStepFactory.php',
        )))
        ->not->toContain('Schema::hasColumn')->and(Schema::hasColumn('workspace_steps', 'app_id'))->toBeFalse();

    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $step = app(AddWorkspaceStep::class)->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        instanceId: $instance->id,
        command: 'composer install',
    );
    $factoryStep = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'npm install',
    ]);

    expect($step->exists)
        ->toBeTrue()
        ->and($step->instance_id)
        ->toBe($instance->id)
        ->and($step->getAttributes())
        ->not->toHaveKey('app_id')->and($factoryStep->exists)->toBeTrue()->and($factoryStep->getAttributes())
        ->not->toHaveKey('app_id')->and($factoryStep->instance->app_id)->toBe($app->id);
});
