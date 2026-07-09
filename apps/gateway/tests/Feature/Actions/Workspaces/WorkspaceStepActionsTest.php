<?php

declare(strict_types=1);

use App\Actions\Workspaces\AddWorkspaceStep;
use App\Actions\Workspaces\RemoveWorkspaceStep;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('orders workspace setup and teardown steps independently', function (): void {
    $app = App::factory()->create();
    $addStep = app(AddWorkspaceStep::class);
    $removeStep = app(RemoveWorkspaceStep::class);

    $first = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'composer install',
    );
    $second = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'php artisan migrate',
    );
    $inserted = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'npm run build',
        beforeStepId: $second->id,
    );
    $teardown = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Teardown,
        command: 'php artisan down',
    );

    expect(
        WorkspaceStep::query()
            ->where('app_id', $app->id)
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
            ->where('app_id', $app->id)
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
    $nmbp = AppInstance::factory()->create(['app_id' => $app->id, 'name' => 'nmbp']);
    $development = AppInstance::factory()->create(['app_id' => $app->id, 'name' => 'development']);
    $addStep = app(AddWorkspaceStep::class);

    $nmbpStep = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'composer install',
        appInstanceId: $nmbp->id,
    );
    $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'npm install',
        appInstanceId: $development->id,
    );

    expect(WorkspaceStep::query()->where('app_instance_id', $nmbp->id)->pluck('command')->all())
        ->toBe(['composer install'])
        ->and(WorkspaceStep::query()->where('app_instance_id', $development->id)->pluck('command')->all())
        ->toBe(['npm install'])
        ->and($nmbpStep->app_instance_id)
        ->toBe($nmbp->id);
});
