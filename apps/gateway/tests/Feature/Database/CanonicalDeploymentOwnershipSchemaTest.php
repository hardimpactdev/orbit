<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores deployment policy and history only on concrete app instances', function (): void {
    expect(Schema::hasColumn('app_instances', 'deploy_warmup_paths'))
        ->toBeTrue()
        ->and(Schema::hasColumn('apps', 'deploy_warmup_paths'))
        ->toBeFalse()
        ->and(Schema::hasColumn('apps', 'latest_deployment_status'))
        ->toBeFalse()
        ->and(Schema::hasColumn('apps', 'latest_deployment_run_id'))
        ->toBeFalse()
        ->and(Schema::hasColumn('deploy_steps', 'app_instance_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('deploy_steps', 'app_id'))
        ->toBeFalse()
        ->and(Schema::hasColumn('deployment_runs', 'app_instance_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('deployment_runs', 'app_id'))
        ->toBeFalse();
});

it('migrates unambiguous deployment state to the sole concrete instance', function (): void {
    with_historical_deployment_schema(function (): void {
        $now = now();

        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'deploy_warmup_paths' => json_encode(['/health'], JSON_THROW_ON_ERROR),
            'latest_deployment_status' => 'completed',
            'latest_deployment_run_id' => 8,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'production',
            'driver' => 'orbit',
            'driver_config' => '{}',
            'latest_deployment_status' => null,
            'latest_deployment_run_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('deploy_steps')->insert([
            'id' => 4,
            'app_id' => 1,
            'title' => 'Migrate',
            'command' => 'php artisan migrate --force',
            'sort_order' => 1,
            'timeout_seconds' => 600,
            'retention' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('deployment_runs')->insert([
            'id' => 8,
            'app_id' => 1,
            'status' => 'completed',
            'exit_code' => 0,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 25,
            'context' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('deployment_run_steps')->insert([
            'id' => 12,
            'deployment_run_id' => 8,
            'deploy_step_id' => 4,
            'title' => 'Migrate',
            'command' => 'php artisan migrate --force',
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        run_canonical_deployment_ownership_migration();

        expect(DB::table('deploy_steps')->where('id', 4)->value('app_instance_id'))
            ->toBe(10)
            ->and(DB::table('deployment_runs')->where('id', 8)->value('app_instance_id'))
            ->toBe(10)
            ->and(DB::table('deployment_run_steps')->where('id', 12)->value('deployment_run_id'))
            ->toBe(8)
            ->and(DB::table('app_instances')->where('id', 10)->value('deploy_warmup_paths'))
            ->toBe(json_encode(['/health'], JSON_THROW_ON_ERROR))
            ->and(DB::table('app_instances')->where('id', 10)->value('latest_deployment_status'))
            ->toBe('completed')
            ->and(DB::table('app_instances')->where('id', 10)->value('latest_deployment_run_id'))
            ->toBe(8)
            ->and(Schema::hasColumn('deploy_steps', 'app_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('deployment_runs', 'app_id'))
            ->toBeFalse();
    });
});

it('fails before schema mutation when deployment ownership is ambiguous', function (): void {
    with_historical_deployment_schema(function (): void {
        $now = now();

        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_instances')->insert([
            historical_deployment_instance(10, name: 'orbit', now: $now),
            historical_deployment_instance(11, name: 'cloud', now: $now),
        ]);
        DB::table('deploy_steps')->insert([
            'id' => 4,
            'app_id' => 1,
            'title' => 'Migrate',
            'command' => 'php artisan migrate --force',
            'sort_order' => 1,
            'timeout_seconds' => 600,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(run_canonical_deployment_ownership_migration(...))
            ->toThrow(RuntimeException::class, 'requires manual assignment')
            ->and(Schema::hasColumn('app_instances', 'deploy_warmup_paths'))
            ->toBeFalse()
            ->and(Schema::hasColumn('deploy_steps', 'app_instance_id'))
            ->toBeFalse()
            ->and(DB::table('deploy_steps')->where('id', 4)->value('app_id'))
            ->toBe(1);
    });
});

function with_historical_deployment_schema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'deployment_ownership_migration_history';

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        create_historical_deployment_schema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function create_historical_deployment_schema(): void
{
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->json('deploy_warmup_paths')->nullable();
        $table->string('latest_deployment_status')->nullable();
        $table->unsignedBigInteger('latest_deployment_run_id')->nullable();
        $table->timestamps();
    });
    Schema::create('app_instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->string('driver');
        $table->json('driver_config');
        $table->string('latest_deployment_status')->nullable();
        $table->unsignedBigInteger('latest_deployment_run_id')->nullable();
        $table->timestamps();
    });
    Schema::create('deploy_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('title');
        $table->text('command');
        $table->unsignedInteger('sort_order');
        $table->unsignedInteger('timeout_seconds');
        $table->unsignedInteger('retention')->nullable();
        $table->timestamps();
        $table->unique(['app_id', 'sort_order']);
        $table->index(['app_id', 'title']);
    });
    Schema::create('deployment_runs', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('status');
        $table->integer('exit_code')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();
        $table->index(['app_id', 'started_at']);
        $table->index(['app_id', 'status']);
    });
    Schema::create('deployment_run_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('deployment_run_id')->constrained('deployment_runs')->cascadeOnDelete();
        $table->foreignId('deploy_step_id')->nullable()->constrained('deploy_steps')->nullOnDelete();
        $table->string('title');
        $table->text('command');
        $table->string('status');
        $table->longText('stdout')->nullable();
        $table->longText('stderr')->nullable();
        $table->integer('exit_code')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->timestamps();
        $table->index(['deployment_run_id', 'deploy_step_id']);
    });
}

function run_canonical_deployment_ownership_migration(): void
{
    /** @var mixed $migration */
    $migration = require database_path('migrations/2026_07_12_090000_canonicalize_deployment_ownership.php');

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Canonical deployment ownership migration must expose up().');
    }

    $migration->up();
}

function historical_deployment_instance(int $id, string $name, mixed $now): array
{
    return [
        'id' => $id,
        'app_id' => 1,
        'name' => $name,
        'driver' => 'orbit',
        'driver_config' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
