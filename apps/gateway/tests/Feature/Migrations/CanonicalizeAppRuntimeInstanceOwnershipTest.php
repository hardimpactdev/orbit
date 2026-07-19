<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('moves historical worker and setup ownership to the sole app instance', function (): void {
    with_historical_app_runtime_ownership_schema(function (): void {
        $now = now();
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'worker_enabled' => true,
            'worker_config' => json_encode(['workers' => 4, 'max_requests' => 500], JSON_THROW_ON_ERROR),
        ]);
        DB::table('app_instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'production',
            'runtime_requirements' => null,
        ]);
        DB::table('app_setup_steps')->insert([
            'id' => 20,
            'app_id' => 1,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 600,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_setup_runs')->insert([
            'id' => 30,
            'app_id' => 1,
            'status' => 'completed',
            'step_set_hash' => 'hash',
            'started_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app_runtime_instance_ownership_migration()->up();

        expect(Schema::hasColumn('apps', 'worker_enabled'))
            ->toBeFalse()
            ->and(Schema::hasColumn('apps', 'worker_config'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_setup_steps', 'app_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_setup_runs', 'app_id'))
            ->toBeFalse()
            ->and(DB::table('app_instances')->where('id', 10)->value('worker_enabled'))
            ->toBe(1)
            ->and(json_decode((string) DB::table('app_instances')->where('id', 10)->value('worker_config'), true))
            ->toBe(['workers' => 4, 'max_requests' => 500])
            ->and(DB::table('app_setup_steps')->where('id', 20)->value('app_instance_id'))
            ->toBe(10)
            ->and(DB::table('app_setup_runs')->where('id', 30)->value('app_instance_id'))
            ->toBe(10);
    });
});

it('stops before schema mutation when historical app runtime ownership is ambiguous', function (): void {
    with_historical_app_runtime_ownership_schema(function (): void {
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'worker_enabled' => true,
            'worker_config' => null,
        ]);
        DB::table('app_instances')->insert([
            ['id' => 10, 'app_id' => 1, 'name' => 'development', 'runtime_requirements' => null],
            ['id' => 11, 'app_id' => 1, 'name' => 'production', 'runtime_requirements' => null],
        ]);

        expect(fn (): mixed => app_runtime_instance_ownership_migration()->up())
            ->toThrow(RuntimeException::class, 'requires manual assignment before migration')
            ->and(Schema::hasColumn('apps', 'worker_enabled'))
            ->toBeTrue()
            ->and(Schema::hasColumn('app_instances', 'worker_enabled'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_setup_steps', 'app_instance_id'))
            ->toBeFalse();
    });
});

function with_historical_app_runtime_ownership_schema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'app_runtime_ownership_migration_history';

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
        create_historical_app_runtime_ownership_schema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function create_historical_app_runtime_ownership_schema(): void
{
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('worker_enabled')->default(false);
        $table->json('worker_config')->nullable();
    });
    Schema::create('app_instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->json('runtime_requirements')->nullable();
    });
    Schema::create('app_setup_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->unsignedInteger('sort_order');
        $table->text('command');
        $table->unsignedInteger('timeout_seconds')->default(600);
        $table->timestamps();
        $table->unique(['app_id', 'sort_order']);
    });
    Schema::create('app_setup_runs', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('status');
        $table->string('step_set_hash')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
        $table->index(['app_id', 'status']);
    });
    Schema::create('app_setup_run_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_setup_run_id')->constrained('app_setup_runs')->cascadeOnDelete();
        $table->foreignId('app_setup_step_id')->nullable()->constrained('app_setup_steps')->nullOnDelete();
    });
}

function app_runtime_instance_ownership_migration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_07_19_020000_canonicalize_app_runtime_instance_ownership.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected app runtime instance ownership migration instance.');
    }

    return $migration;
}
