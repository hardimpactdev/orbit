<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('makes workspace ownership concrete and removes app-level compatibility tables', function (): void {
    $workspaceInstanceColumn = canonical_schema_column('workspaces', 'instance_id');
    $workspaceStepInstanceColumn = canonical_schema_column('workspace_steps', 'instance_id');

    expect($workspaceInstanceColumn)
        ->toBeArray()
        ->and($workspaceInstanceColumn['nullable'] ?? null)
        ->toBeFalse()
        ->and($workspaceStepInstanceColumn)
        ->toBeArray()
        ->and($workspaceStepInstanceColumn['nullable'] ?? null)
        ->toBeFalse()
        ->and(Schema::hasTable('app_runtime_mounts'))
        ->toBeFalse();
});

it('persists only app-instance and workspace database targets', function (): void {
    expect(Schema::hasColumn('database_connection_targets', 'app_id'))
        ->toBeFalse()
        ->and(Schema::hasColumn('database_connection_targets', 'instance_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('database_connection_targets', 'workspace_id'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instance_database_connection_targets'))
        ->toBeFalse();
});

it('migrates unambiguous historical ownership and copies only dependent step phases', function (): void {
    with_historical_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert(['id' => 1, 'name' => 'beast']);
        DB::table('apps')->insert([
            historical_ownership_app(1, 'multi', $now),
            historical_ownership_app(2, 'canonical', $now),
        ]);
        DB::table('app_instances')->insert([
            historical_ownership_instance(1, 1, 'development', $now),
            historical_ownership_instance(2, 1, 'nmbp', $now),
        ]);
        DB::table('workspace_steps')->insert([
            historical_ownership_step(1, null, 'setup', 'composer install', $now),
            historical_ownership_step(1, 1, 'setup', 'instance override', $now),
            historical_ownership_step(2, null, 'teardown', 'php artisan down', $now),
        ]);
        DB::table('workspaces')->insert([
            'id' => 1,
            'app_id' => 2,
            'app_instance_id' => null,
            'name' => 'feature',
            'path' => '/srv/canonical/.worktrees/feature',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_runtime_mounts')->insert([
            'id' => 1,
            'app_id' => 2,
            'source' => '/home/orbit/data',
            'target' => '/data-extra',
            'read_only' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('database_connections')->insert([
            ['id' => 1, 'slug' => 'primary', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'slug' => 'workspace', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('database_connection_targets')->insert([
            [
                'id' => 1,
                'database_connection_id' => 1,
                'app_id' => 2,
                'workspace_id' => null,
                'env_prefix' => 'DB',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'database_connection_id' => 2,
                'app_id' => null,
                'workspace_id' => 1,
                'env_prefix' => 'REPORTING_DB',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        run_canonical_ownership_migration();

        $canonicalInstanceId = (int) DB::table('app_instances')->where('app_id', 2)->value('id');

        expect($canonicalInstanceId)
            ->toBeGreaterThan(0)
            ->and(DB::table('workspaces')->where('id', 1)->value('app_instance_id'))
            ->toBe($canonicalInstanceId)
            ->and(DB::table('workspace_steps')->whereNull('app_instance_id')->count())
            ->toBe(0)
            ->and(DB::table('workspace_steps')->where('app_instance_id', 1)->pluck('command')->all())
            ->toBe(['instance override'])
            ->and(DB::table('workspace_steps')->where('app_instance_id', 2)->pluck('command')->all())
            ->toBe(['composer install'])
            ->and(DB::table('workspace_steps')->where('app_instance_id', $canonicalInstanceId)->pluck('command')->all())
            ->toBe(['php artisan down'])
            ->and(
                DB::table('app_instance_runtime_mounts')
                    ->where('app_instance_id', $canonicalInstanceId)
                    ->value('target'),
            )
            ->toBe('/data-extra')
            ->and(
                DB::table('database_connection_targets')
                    ->where('app_instance_id', $canonicalInstanceId)
                    ->value('database_connection_id'),
            )
            ->toBe(1)
            ->and(DB::table('database_connection_targets')->where('workspace_id', 1)->value('database_connection_id'))
            ->toBe(2)
            ->and(Schema::hasTable('app_runtime_mounts'))
            ->toBeFalse()
            ->and(Schema::hasTable('app_instance_database_connection_targets'))
            ->toBeFalse();
    });
});

it('migrates app-level targets to the matching Orbit instance beside a cloud instance', function (): void {
    with_historical_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert(['id' => 1, 'name' => 'beast']);
        DB::table('apps')->insert(historical_ownership_app(1, 'platform11', $now));
        DB::table('app_instances')->insert([
            [
                ...historical_ownership_instance(1, 1, 'cloud', $now),
                'driver' => 'laravel-cloud',
                'driver_config' => json_encode([
                    'type' => 'laravel_cloud_app_instance_driver_config',
                    'data' => ['domain' => 'cloud.platform11.nl'],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                ...historical_ownership_instance(2, 1, 'development', $now),
                'driver_config' => json_encode([
                    'type' => 'orbit_app_instance_driver_config',
                    'data' => [
                        'node_id' => 1,
                        'node' => 'beast',
                        'path' => '/srv/platform11',
                        'document_root' => 'public',
                        'domain' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        DB::table('workspaces')->insert([
            'id' => 1,
            'app_id' => 1,
            'app_instance_id' => null,
            'name' => 'feature',
            'path' => '/srv/platform11/.worktrees/feature',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_runtime_mounts')->insert([
            'id' => 1,
            'app_id' => 1,
            'source' => '/srv/platform11/storage',
            'target' => '/app/storage',
            'read_only' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('database_connections')->insert([
            [
                'id' => 1,
                'slug' => 'platform11',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'slug' => 'platform11-cloud',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('database_connection_targets')->insert([
            'id' => 1,
            'database_connection_id' => 1,
            'app_id' => 1,
            'workspace_id' => null,
            'env_prefix' => 'DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_instance_database_connection_targets')->insert([
            'id' => 1,
            'app_instance_id' => 1,
            'database_connection_id' => 2,
            'env_prefix' => 'DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        run_canonical_ownership_migration();

        expect(DB::table('workspaces')->where('id', 1)->value('app_instance_id'))
            ->toBe(2)
            ->and(DB::table('app_instance_runtime_mounts')->where('target', '/app/storage')->value('app_instance_id'))
            ->toBe(2)
            ->and(
                DB::table('database_connection_targets')
                    ->where('env_prefix', 'DB')
                    ->where('database_connection_id', 1)
                    ->value('app_instance_id'),
            )
            ->toBe(2)
            ->and(
                DB::table('database_connection_targets')->where('app_instance_id', 1)->value('database_connection_id'),
            )
            ->toBe(2);
    });
});

it('fails before mutation when the resolved Orbit instance has a conflicting database target', function (): void {
    with_historical_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert(['id' => 1, 'name' => 'beast']);
        DB::table('apps')->insert(historical_ownership_app(1, 'platform11', $now));
        DB::table('app_instances')->insert([
            [
                ...historical_ownership_instance(1, 1, 'cloud', $now),
                'driver' => 'laravel-cloud',
                'driver_config' => json_encode([
                    'type' => 'laravel_cloud_app_instance_driver_config',
                    'data' => ['domain' => 'cloud.platform11.nl'],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                ...historical_ownership_instance(2, 1, 'development', $now),
                'driver_config' => json_encode([
                    'type' => 'orbit_app_instance_driver_config',
                    'data' => [
                        'node_id' => 1,
                        'node' => 'beast',
                        'path' => '/srv/platform11',
                        'document_root' => 'public',
                        'domain' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        DB::table('database_connections')->insert([
            [
                'id' => 1,
                'slug' => 'platform11',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'slug' => 'conflict',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('database_connection_targets')->insert([
            'id' => 1,
            'database_connection_id' => 1,
            'app_id' => 1,
            'workspace_id' => null,
            'env_prefix' => 'DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_instance_database_connection_targets')->insert([
            'id' => 1,
            'app_instance_id' => 2,
            'database_connection_id' => 2,
            'env_prefix' => 'DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(run_canonical_ownership_migration(...))
            ->toThrow(RuntimeException::class, 'conflicting assignments')
            ->and(DB::table('database_connection_targets')->where('id', 1)->value('app_id'))
            ->toBe(1)
            ->and(Schema::hasTable('app_runtime_mounts'))
            ->toBeTrue();
    });
});

it('fails before mutation when historical ownership is ambiguous', function (): void {
    with_historical_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert(['id' => 1, 'name' => 'beast']);
        DB::table('apps')->insert(historical_ownership_app(1, 'ambiguous', $now));
        DB::table('app_instances')->insert([
            historical_ownership_instance(1, 1, 'development', $now),
            historical_ownership_instance(2, 1, 'production', $now),
        ]);
        DB::table('workspaces')->insert([
            'id' => 1,
            'app_id' => 1,
            'app_instance_id' => null,
            'name' => 'feature',
            'path' => '/srv/ambiguous/.worktrees/feature',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(run_canonical_ownership_migration(...))
            ->toThrow(RuntimeException::class, 'requires manual assignment')
            ->and(DB::table('workspaces')->where('id', 1)->value('app_instance_id'))
            ->toBeNull()
            ->and(Schema::hasTable('app_runtime_mounts'))
            ->toBeTrue();
    });
});

function with_historical_ownership_schema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'ownership_migration_history';

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
        create_historical_ownership_schema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function create_historical_ownership_schema(): void
{
    Schema::create('nodes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes');
        $table->string('name');
        $table->string('environment')->nullable();
        $table->string('path');
        $table->string('document_root')->nullable();
        $table->string('domain')->nullable();
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
        $table->json('runtime_requirements');
        $table->string('latest_deployment_status')->nullable();
        $table->unsignedBigInteger('latest_deployment_run_id')->nullable();
        $table->timestamps();
    });
    Schema::create('workspaces', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->cascadeOnDelete();
        $table->string('name');
        $table->string('path');
        $table->timestamps();
    });
    Schema::create('workspace_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->cascadeOnDelete();
        $table->string('phase');
        $table->unsignedInteger('sort_order');
        $table->text('command');
        $table->unsignedInteger('timeout_seconds');
        $table->timestamps();
    });
    Schema::create('app_runtime_mounts', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('source');
        $table->string('target');
        $table->boolean('read_only');
        $table->timestamps();
    });
    Schema::create('app_instance_runtime_mounts', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('source');
        $table->string('target');
        $table->boolean('read_only');
        $table->timestamps();
        $table->unique(['app_instance_id', 'target']);
    });
    Schema::create('database_connections', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
        $table->timestamps();
    });
    Schema::create('database_connection_targets', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
        $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
        $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
        $table->string('env_prefix');
        $table->timestamps();
        $table->unique(['app_id', 'env_prefix']);
        $table->unique(['workspace_id', 'env_prefix']);
    });
    Schema::create('app_instance_database_connection_targets', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
        $table->string('env_prefix');
        $table->timestamps();
        $table->unique(['app_instance_id', 'env_prefix']);
    });
}

function run_canonical_ownership_migration(): void
{
    /** @var mixed $migration */
    $migration = require database_path('migrations/2026_07_12_084244_canonicalize_app_instance_ownership.php');

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Canonical ownership migration must expose up().');
    }

    $migration->up();
}

function historical_ownership_app(int $id, string $name, mixed $now): array
{
    return [
        'id' => $id,
        'node_id' => 1,
        'name' => $name,
        'environment' => null,
        'path' => "/srv/{$name}",
        'document_root' => 'public',
        'domain' => "{$name}.test",
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function historical_ownership_instance(int $id, int $appId, string $name, mixed $now): array
{
    return [
        'id' => $id,
        'app_id' => $appId,
        'name' => $name,
        'driver' => 'orbit',
        'driver_config' => '{}',
        'runtime_requirements' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function historical_ownership_step(
    int $appId,
    ?int $instanceId,
    string $phase,
    string $command,
    mixed $now,
): array {
    return [
        'app_id' => $appId,
        'app_instance_id' => $instanceId,
        'phase' => $phase,
        'sort_order' => 1,
        'command' => $command,
        'timeout_seconds' => 600,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

/** @return array<string, mixed> */
function canonical_schema_column(string $table, string $name): array
{
    /** @var list<array<string, mixed>> $columns */
    $columns = Schema::getColumns($table);

    foreach ($columns as $column) {
        if (($column['name'] ?? null) === $name) {
            return $column;
        }
    }

    throw new RuntimeException("Missing {$table}.{$name} schema column.");
}
