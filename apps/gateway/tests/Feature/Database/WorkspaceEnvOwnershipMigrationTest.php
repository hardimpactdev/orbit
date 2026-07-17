<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\WorkspaceStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes legacy setup and teardown steps that consume the parent env', function (): void {
    $app = App::factory()->create();
    $instance = AppInstance::factory()->for($app)->create();
    $unsafeSetup = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'cp "$ORBIT_APP_PATH/.env" .env',
    ]);
    $unsafeTeardown = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Teardown,
        'command' => 'cp "${ORBIT_APP_PATH}/.env" .env.backup',
    ]);
    $unsafeQuotedVariable = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'cp "$ORBIT_APP_PATH"/.env .env',
    ]);
    $unsafeQuotedBracedVariable = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Teardown,
        'command' => 'cp "${ORBIT_APP_PATH}"/.env .env.backup',
    ]);
    $safe = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'command' => 'composer install',
    ]);

    run_workspace_env_ownership_migration();

    expect(WorkspaceStep::query()->whereKey($unsafeSetup->id)->exists())
        ->toBeFalse()
        ->and(WorkspaceStep::query()->whereKey($unsafeTeardown->id)->exists())
        ->toBeFalse()
        ->and(WorkspaceStep::query()->whereKey($unsafeQuotedVariable->id)->exists())
        ->toBeFalse()
        ->and(WorkspaceStep::query()->whereKey($unsafeQuotedBracedVariable->id)->exists())
        ->toBeFalse()
        ->and(WorkspaceStep::query()->whereKey($safe->id)->exists())
        ->toBeTrue();
});

function run_workspace_env_ownership_migration(): void
{
    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_07_16_191349_remove_parent_env_workspace_steps.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Workspace env ownership migration must expose up().');
    }

    $migration->up();
}
