<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\WorkspaceStepListPayload;
use App\Services\Workspaces\WorkspaceStepPolicyService;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('derives WorkspaceStep App through Instance and does not persist app_id', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
    ]);

    expect(new WorkspaceStep()->getFillable())
        ->not
        ->toContain('app_id')
        ->and($step->app())
        ->toBeInstanceOf(HasOneThrough::class)
        ->and($step->app?->id)
        ->toBe($app->id)
        ->and($step->instance->app_id)
        ->toBe($app->id);
});

it('keeps WorkspaceStep policy allow and deny identical for consistent Instance-owned rows', function (): void {
    $app = App::factory()->create();
    $otherApp = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $otherInstance = Instance::factory()->create(['app_id' => $otherApp->id]);
    $policy = app(WorkspaceStepPolicyService::class);

    $step = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
        'sort_order' => 1,
    ]);
    WorkspaceStep::factory()->create([
        'app_id' => $otherApp->id,
        'instance_id' => $otherInstance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'npm install',
        'sort_order' => 1,
    ]);

    expect($policy->findInstanceStep($app, WorkspaceLifecyclePhase::Setup, $step->id, $instance))
        ->not
        ->toBeNull()
        ->and($policy->findInstanceStep($otherApp, WorkspaceLifecyclePhase::Setup, $step->id, $instance))
        ->toBeNull()
        ->and($policy->findInstanceStep($app, WorkspaceLifecyclePhase::Teardown, $step->id, $instance))
        ->toBeNull()
        ->and($policy->findInstanceStep($app, WorkspaceLifecyclePhase::Setup, $step->id, $otherInstance))
        ->toBeNull()
        ->and($policy->hasStepsFor($app, WorkspaceLifecyclePhase::Setup, $instance))
        ->toBeTrue()
        ->and($policy->hasStepsFor($otherApp, WorkspaceLifecyclePhase::Setup, $instance))
        ->toBeFalse()
        ->and($policy->remainingInstanceCount($app, WorkspaceLifecyclePhase::Setup, $instance))
        ->toBe(1)
        ->and($policy->stepsFor($app, WorkspaceLifecyclePhase::Setup, $instance)->pluck('id')->all())
        ->toBe([$step->id]);
});

it('handles a deleted WorkspaceStep instance without throwing', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
    ]);
    $policy = app(WorkspaceStepPolicyService::class);
    $step = orphan_workspace_step_instance($step, $instance);

    expect($step->instance)
        ->toBeNull()
        ->and($step->app)
        ->toBeNull()
        ->and(fn (): mixed => $policy->findInstanceStep(
            $app,
            WorkspaceLifecyclePhase::Setup,
            $step->id,
            $instance,
        ))
        ->not
        ->toThrow(Throwable::class)
        ->and($policy->findInstanceStep($app, WorkspaceLifecyclePhase::Setup, $step->id, $instance))
        ->toBeNull();
});

it('serializes a consistent WorkspaceStep with Instance-derived App output unchanged', function (): void {
    $app = App::factory()->create(['name' => 'happie']);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'nmbp',
    ]);
    $step = WorkspaceStep::factory()->create([
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
        'sort_order' => 3,
        'timeout_seconds' => 90,
    ]);

    expect(app(WorkspaceStepListPayload::class)->forStep($step, $app))->toBe([
        'id' => $step->id,
        'app' => 'happie',
        'instance' => 'nmbp',
        'phase' => WorkspaceLifecyclePhase::Setup->value,
        'order' => 3,
        'command' => 'composer install',
        'timeout_seconds' => 90,
    ]);
});

it('fails closed when a workspace step app_id disagrees with its instance', function (): void {
    $app = App::factory()->create();
    $otherApp = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
    ]);

    restore_workspace_steps_app_id_column();

    DB::table('workspace_steps')
        ->where('id', $step->id)
        ->update([
            'app_id' => $otherApp->id,
        ]);

    expect(run_workspace_step_app_ownership_migration(...))
        ->toThrow(RuntimeException::class, (string) $step->id)
        ->and(Schema::hasColumn('workspace_steps', 'app_id'))
        ->toBeTrue()
        ->and((int) DB::table('workspace_steps')->where('id', $step->id)->value('app_id'))
        ->toBe($otherApp->id);
});

it('fails closed when a workspace step instance is missing', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
    ]);

    restore_workspace_steps_app_id_column();
    orphan_workspace_step_instance($step, $instance);

    expect(run_workspace_step_app_ownership_migration(...))
        ->toThrow(RuntimeException::class, (string) $step->id)
        ->and(Schema::hasColumn('workspace_steps', 'app_id'))
        ->toBeTrue()
        ->and(DB::table('workspace_steps')->where('id', $step->id)->exists())
        ->toBeTrue();
});

it('drops workspace_steps.app_id after a consistent Instance-owned audit', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
    ]);

    restore_workspace_steps_app_id_column();
    run_workspace_step_app_ownership_migration();

    $step->refresh();

    expect(Schema::hasColumn('workspace_steps', 'app_id'))
        ->toBeFalse()
        ->and($step->getAttributes())
        ->not
        ->toHaveKey('app_id')
        ->and(DB::table('workspace_steps')->where('id', $step->id)->exists())
        ->toBeTrue()
        ->and((int) DB::table('workspace_steps')->where('id', $step->id)->value('instance_id'))
        ->toBe($instance->id);
});

function restore_workspace_steps_app_id_column(): void
{
    if (Schema::hasColumn('workspace_steps', 'app_id')) {
        return;
    }

    Schema::table('workspace_steps', static function (Blueprint $table): void {
        $table->unsignedBigInteger('app_id')->nullable();
    });

    foreach (DB::table('workspace_steps')->select(['id', 'instance_id'])->get() as $step) {
        $appId = DB::table('instances')->where('id', $step->instance_id)->value('app_id');

        DB::table('workspace_steps')
            ->where('id', $step->id)
            ->update([
                'app_id' => $appId,
            ]);
    }
}

function orphan_workspace_step_instance(WorkspaceStep $step, Instance $instance): WorkspaceStep
{
    Schema::table('workspace_steps', function (Blueprint $table): void {
        $table->dropForeign(['instance_id']);
    });

    DB::table('instances')->where('id', $instance->id)->delete();

    $orphaned = WorkspaceStep::query()->findOrFail($step->id);
    $orphaned->unsetRelation('instance');
    $orphaned->unsetRelation('app');

    return $orphaned;
}

function run_workspace_step_app_ownership_migration(): void
{
    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_08_18_120000_drop_redundant_app_id_from_workspace_steps_table.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('WorkspaceStep app ownership migration must expose up().');
    }

    $migration->up();
}
