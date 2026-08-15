<?php

declare(strict_types=1);

use App\Models\DeploymentRun;
use App\Models\Instance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function restoreInstanceDeploymentShadowColumns(): void
{
    if (Schema::hasColumn('instances', 'latest_deployment_status')) {
        return;
    }

    Schema::table('instances', static function (Blueprint $table): void {
        $table->string('latest_deployment_status')->nullable();
        $table->unsignedBigInteger('latest_deployment_run_id')->nullable();
    });
}

function removeInstanceDeploymentShadowColumnsMigration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_08_15_101835_remove_latest_deployment_state_from_instances_table.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Deployment shadow removal migration must be a Migration.');
    }

    return $migration;
}

it('drops equivalent shadows while preserving deterministic latest run state', function (): void {
    restoreInstanceDeploymentShadowColumns();
    Carbon::setTestNow('2026-08-15 12:00:00');

    try {
        $instance = Instance::factory()->create();
        $instanceWithoutRuns = Instance::factory()->create();
        DeploymentRun::query()->create([
            'instance_id' => $instance->id,
            'status' => 'completed',
            'exit_code' => 0,
            'started_at' => Carbon::now(),
        ]);
        $latestRun = DeploymentRun::query()->create([
            'instance_id' => $instance->id,
            'status' => 'failed',
            'exit_code' => 1,
            'started_at' => Carbon::now(),
        ]);
    } finally {
        Carbon::setTestNow();
    }

    DB::table('instances')
        ->where('id', $instance->id)
        ->update([
            'latest_deployment_status' => $latestRun->status,
            'latest_deployment_run_id' => $latestRun->id,
        ]);

    removeInstanceDeploymentShadowColumnsMigration()->up();

    expect(Schema::hasColumn('instances', 'latest_deployment_status'))
        ->toBeFalse()
        ->and(Schema::hasColumn('instances', 'latest_deployment_run_id'))
        ->toBeFalse()
        ->and(DeploymentRun::query()->where('instance_id', $instance->id)->count())
        ->toBe(2)
        ->and($instance->refresh()->latestDeploymentRun->is($latestRun))
        ->toBeTrue()
        ->and($instanceWithoutRuns->refresh()->latestDeploymentRun)
        ->toBeNull();
});

it('refuses to drop deployment shadows when durable run state is not equivalent', function (): void {
    restoreInstanceDeploymentShadowColumns();
    $instance = Instance::factory()->create();
    DeploymentRun::query()->create([
        'instance_id' => $instance->id,
        'status' => 'failed',
        'exit_code' => 1,
        'started_at' => Carbon::now(),
    ]);

    DB::table('instances')
        ->where('id', $instance->id)
        ->update([
            'latest_deployment_status' => 'completed',
            'latest_deployment_run_id' => null,
        ]);

    expect(fn () => removeInstanceDeploymentShadowColumnsMigration()->up())
        ->toThrow(
            RuntimeException::class,
            "Instance {$instance->id} deployment shadow state does not match its latest deployment run.",
        )
        ->and(Schema::hasColumn('instances', 'latest_deployment_status'))
        ->toBeTrue()
        ->and(Schema::hasColumn('instances', 'latest_deployment_run_id'))
        ->toBeTrue();
});
