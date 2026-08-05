<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release-critical proof for 2026_08_05 vocabulary cutover migration.
 *
 * Constructs the immediately-pre-cutover SQLite registry (app_instances +
 * app_instance_id ownership + app:* grants), seeds representative rows on
 * every instance-owned surface, runs the migration once, and asserts data,
 * renamed tables/columns, FK targets, and unique/index columns.
 */
it('renames pre-cutover instance storage and rewrites project grants while preserving rows', function (): void {
    with_pre_cutover_instance_vocabulary_schema(function (): void {
        $now = '2026-08-05 12:00:00';

        DB::table('nodes')->insert([
            ['id' => 1, 'name' => 'app-1', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'operator', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('apps')->insert([
            'id' => 10,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('app_instances')->insert([
            [
                'id' => 100,
                'app_id' => 10,
                'name' => 'development',
                'driver' => 'orbit',
                'driver_config' => json_encode([
                    'type' => 'orbit_app_instance_driver_config',
                    'data' => [
                        'node_id' => 1,
                        'node' => 'app-1',
                        'path' => '/home/orbit/apps/docs',
                        'document_root' => 'public',
                    ],
                ], JSON_THROW_ON_ERROR),
                'runtime_requirements' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 101,
                'app_id' => 10,
                'name' => 'production',
                'driver' => 'laravel-cloud',
                'driver_config' => json_encode([
                    'type' => 'laravel_cloud_app_instance_driver_config',
                    'data' => ['domain' => 'docs.example.com'],
                ], JSON_THROW_ON_ERROR),
                'runtime_requirements' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('app_instance_env_variables')->insert([
            'id' => 1,
            'app_instance_id' => 100,
            'key' => 'APP_ENV',
            'value' => 'local',
            'secret' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('app_instance_runtime_mounts')->insert([
            'id' => 1,
            'app_instance_id' => 100,
            'source' => '/home/orbit/data',
            'target' => '/data',
            'read_only' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workspaces')->insert([
            'id' => 1,
            'app_id' => 10,
            'app_instance_id' => 100,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workspace_steps')->insert([
            'id' => 1,
            'app_id' => 10,
            'app_instance_id' => 100,
            'phase' => 'setup',
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 600,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('processes')->insert([
            'id' => 1,
            'node_id' => 1,
            'owner_type' => 'App\\Models\\App',
            'owner_id' => 10,
            'app_instance_id' => 100,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('process_events')->insert([
            'id' => 1,
            'event' => 'started',
            'event_id' => 'evt-1',
            'process_id' => 1,
            'app_id' => 10,
            'app_instance_id' => 100,
            'workspace_id' => null,
            'node_id' => 1,
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('schedules')->insert([
            'id' => 1,
            'name' => 'nightly',
            'scope' => 'app',
            'app_id' => 10,
            'app_instance_id' => 100,
            'schedule_key' => 'docs.development.nightly',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('deploy_steps')->insert([
            'id' => 1,
            'app_id' => 10,
            'app_instance_id' => 101,
            'title' => 'migrate',
            'command' => 'php artisan migrate --force',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('deployment_runs')->insert([
            'id' => 1,
            'app_id' => 10,
            'app_instance_id' => 101,
            'status' => 'succeeded',
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('app_setup_steps')->insert([
            'id' => 1,
            'app_id' => 10,
            'app_instance_id' => 100,
            'command' => 'npm ci',
            'sort_order' => 1,
            'timeout_seconds' => 600,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('app_setup_runs')->insert([
            'id' => 1,
            'app_id' => 10,
            'app_instance_id' => 100,
            'status' => 'succeeded',
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('database_connections')->insert([
            'id' => 1,
            'slug' => 'primary',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('database_connection_targets')->insert([
            'id' => 1,
            'database_connection_id' => 1,
            'app_instance_id' => 100,
            'workspace_id' => null,
            'env_prefix' => 'DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('node_access')->insert([
            'id' => 1,
            'consumer_node_id' => 2,
            'serving_node_id' => 1,
            // Pre-cutover project workload tokens — cutover maps these to app:*.
            'permissions' => json_encode(
                ['project:read', 'project:write', 'project:list', 'instance:read', 'node:read'],
                JSON_THROW_ON_ERROR,
            ),
            'custom_permissions' => json_encode(
                ['project:new', 'project:remove', 'project:*', 'instance:register'],
                JSON_THROW_ON_ERROR,
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $preCounts = [
            'app_instances' => 2,
            'app_instance_env_variables' => 1,
            'app_instance_runtime_mounts' => 1,
            'workspaces' => 1,
            'workspace_steps' => 1,
            'processes' => 1,
            'process_events' => 1,
            'schedules' => 1,
            'deploy_steps' => 1,
            'deployment_runs' => 1,
            'app_setup_steps' => 1,
            'app_setup_runs' => 1,
            'database_connection_targets' => 1,
            'node_access' => 1,
        ];

        foreach ($preCounts as $table => $count) {
            expect(DB::table($table)->count())->toBe($count, "pre-cutover {$table} row count");
        }

        run_app_instance_vocabulary_cutover_migration();

        expect(Schema::hasTable('app_instances'))
            ->toBeFalse()
            ->and(Schema::hasTable('instances'))
            ->toBeTrue()
            ->and(Schema::hasTable('app_instance_env_variables'))
            ->toBeFalse()
            ->and(Schema::hasTable('instance_env_variables'))
            ->toBeTrue()
            ->and(Schema::hasTable('app_instance_runtime_mounts'))
            ->toBeFalse()
            ->and(Schema::hasTable('instance_runtime_mounts'))
            ->toBeTrue();

        $ownedTables = [
            'instance_env_variables',
            'instance_runtime_mounts',
            'workspaces',
            'workspace_steps',
            'processes',
            'process_events',
            'schedules',
            'deploy_steps',
            'deployment_runs',
            'app_setup_steps',
            'app_setup_runs',
            'database_connection_targets',
        ];

        foreach ($ownedTables as $table) {
            expect(Schema::hasColumn($table, 'instance_id'))
                ->toBeTrue("{$table}.instance_id")
                ->and(Schema::hasColumn($table, 'app_instance_id'))
                ->toBeFalse("{$table}.app_instance_id residual");
        }

        expect(DB::table('instances')->count())
            ->toBe(2)
            ->and(DB::table('instances')->orderBy('id')->pluck('id')->all())
            ->toBe([100, 101])
            ->and(DB::table('instances')->where('id', 100)->value('name'))
            ->toBe('development')
            ->and(DB::table('instances')->where('id', 101)->value('name'))
            ->toBe('production');

        $devConfig = (string) DB::table('instances')->where('id', 100)->value('driver_config');
        $prodConfig = (string) DB::table('instances')->where('id', 101)->value('driver_config');

        expect($devConfig)
            ->toContain('orbit_instance_driver_config')
            ->not->toContain('orbit_app_instance_driver_config')->and($prodConfig)->toContain(
                'laravel_cloud_instance_driver_config',
            )
            ->not->toContain('laravel_cloud_app_instance_driver_config');

        expect(DB::table('instance_env_variables')->count())
            ->toBe(1)
            ->and((int) DB::table('instance_env_variables')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('instance_env_variables')->where('id', 1)->value('key'))
            ->toBe('APP_ENV')
            ->and(DB::table('instance_runtime_mounts')->count())
            ->toBe(1)
            ->and((int) DB::table('instance_runtime_mounts')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('instance_runtime_mounts')->where('id', 1)->value('target'))
            ->toBe('/data')
            ->and((int) DB::table('workspaces')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('workspaces')->where('id', 1)->value('name'))
            ->toBe('feature')
            ->and((int) DB::table('workspace_steps')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('workspace_steps')->where('id', 1)->value('command'))
            ->toBe('composer install')
            ->and((int) DB::table('processes')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('processes')->where('id', 1)->value('name'))
            ->toBe('queue')
            ->and((int) DB::table('process_events')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('process_events')->where('id', 1)->value('event_id'))
            ->toBe('evt-1')
            ->and((int) DB::table('schedules')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('docs.development.nightly')
            ->and((int) DB::table('deploy_steps')->where('id', 1)->value('instance_id'))
            ->toBe(101)
            ->and(DB::table('deploy_steps')->where('id', 1)->value('title'))
            ->toBe('migrate')
            ->and((int) DB::table('deployment_runs')->where('id', 1)->value('instance_id'))
            ->toBe(101)
            ->and(DB::table('deployment_runs')->where('id', 1)->value('status'))
            ->toBe('succeeded')
            ->and((int) DB::table('app_setup_steps')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('app_setup_steps')->where('id', 1)->value('command'))
            ->toBe('npm ci')
            ->and((int) DB::table('app_setup_runs')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('app_setup_runs')->where('id', 1)->value('status'))
            ->toBe('succeeded')
            ->and((int) DB::table('database_connection_targets')->where('id', 1)->value('instance_id'))
            ->toBe(100)
            ->and(DB::table('database_connection_targets')->where('id', 1)->value('env_prefix'))
            ->toBe('DB');

        $grant = DB::table('node_access')->where('id', 1)->first();
        $permissions = json_decode((string) $grant->permissions, true, flags: JSON_THROW_ON_ERROR);
        $custom = json_decode((string) $grant->custom_permissions, true, flags: JSON_THROW_ON_ERROR);

        expect($permissions)
            ->toBe(['app:read', 'app:write', 'app:list', 'instance:read', 'node:read'])
            ->and($custom)
            ->toBe(['app:new', 'app:remove', 'app:*', 'instance:register'])
            ->and(array_values(array_filter($permissions, fn (string $p): bool => str_starts_with($p, 'project:'))))
            ->toBeEmpty()
            ->and(array_values(array_filter($custom, fn (string $p): bool => str_starts_with($p, 'project:'))))
            ->toBeEmpty();

        expect(sqlite_fk_targets('workspaces'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('instance_env_variables'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('instance_runtime_mounts'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('processes'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('process_events'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('schedules'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('deploy_steps'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('deployment_runs'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('app_setup_steps'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('app_setup_runs'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id'])
            ->and(sqlite_fk_targets('database_connection_targets'))
            ->toContain(['from' => 'instance_id', 'table' => 'instances', 'to' => 'id']);

        expect(sqlite_index_column_sets('instance_env_variables'))
            ->toContain(['instance_id', 'key'])
            ->and(sqlite_index_column_sets('instance_runtime_mounts'))
            ->toContain(['instance_id', 'target'])
            ->and(sqlite_index_column_sets('processes'))
            ->toContain(['owner_type', 'owner_id', 'instance_id', 'name'])
            ->and(sqlite_index_column_sets('deploy_steps'))
            ->toContain(['instance_id', 'sort_order'])
            ->and(sqlite_index_column_sets('app_setup_steps'))
            ->toContain(['instance_id', 'sort_order'])
            ->and(sqlite_index_column_sets('instances'))
            ->toContain(['app_id', 'name']);

        foreach (array_merge($ownedTables, ['instances']) as $table) {
            foreach (sqlite_index_column_sets($table) as $columns) {
                expect($columns)->not->toContain('app_instance_id');
            }
        }
    });
});

/**
 * @param  Closure(): void  $callback
 */
function with_pre_cutover_instance_vocabulary_schema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'app_instance_vocabulary_cutover';

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
        create_pre_cutover_instance_vocabulary_schema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function create_pre_cutover_instance_vocabulary_schema(): void
{
    Schema::create('nodes', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->timestamps();
    });

    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes');
        $table->string('name');
        $table->string('path');
        $table->string('document_root')->nullable();
        $table->timestamps();
    });

    Schema::create('app_instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->string('driver');
        $table->json('driver_config');
        $table->json('runtime_requirements')->nullable();
        $table->string('latest_deployment_status')->nullable();
        $table->unsignedBigInteger('latest_deployment_run_id')->nullable();
        $table->timestamps();
        $table->unique(['app_id', 'name']);
        $table->index('driver');
    });

    Schema::create('app_instance_env_variables', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('key');
        $table->text('value')->nullable();
        $table->boolean('secret')->default(false);
        $table->timestamps();
        $table->unique(['app_instance_id', 'key']);
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

    Schema::create('workspaces', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('name');
        $table->string('path');
        $table->timestamps();
        $table->index(['app_instance_id', 'name']);
    });

    Schema::create('workspace_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('phase');
        $table->unsignedInteger('sort_order');
        $table->text('command');
        $table->unsignedInteger('timeout_seconds');
        $table->timestamps();
    });

    Schema::create('processes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
        $table->string('owner_type');
        $table->unsignedBigInteger('owner_id');
        $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->cascadeOnDelete();
        $table->string('name');
        $table->text('command');
        $table->unsignedInteger('sort_order');
        $table->timestamps();
        $table->index(['app_instance_id', 'sort_order']);
        $table->unique(['owner_type', 'owner_id', 'app_instance_id', 'name']);
    });

    Schema::create('process_events', function (Blueprint $table): void {
        $table->id();
        $table->string('event');
        $table->string('event_id')->unique();
        $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
        $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
        $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->nullOnDelete();
        $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
        $table->timestamp('recorded_at');
        $table->timestamps();
        $table->index(['app_instance_id', 'recorded_at']);
    });

    Schema::create('schedules', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('scope');
        $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->cascadeOnDelete();
        $table->string('schedule_key');
        $table->timestamps();
        $table->index(['app_instance_id', 'name']);
    });

    Schema::create('deploy_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('title');
        $table->text('command');
        $table->unsignedInteger('sort_order');
        $table->timestamps();
        $table->unique(['app_instance_id', 'sort_order']);
        $table->index(['app_instance_id', 'title']);
    });

    Schema::create('deployment_runs', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('status');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
        $table->index(['app_instance_id', 'started_at']);
        $table->index(['app_instance_id', 'status']);
    });

    Schema::create('app_setup_steps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->text('command');
        $table->unsignedInteger('sort_order');
        $table->unsignedInteger('timeout_seconds');
        $table->timestamps();
        $table->unique(['app_instance_id', 'sort_order']);
    });

    Schema::create('app_setup_runs', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
        $table->string('status');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
        $table->index(['app_instance_id', 'status']);
    });

    Schema::create('database_connections', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
        $table->timestamps();
    });

    Schema::create('database_connection_targets', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->nullable()->constrained('app_instances')->cascadeOnDelete();
        $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
        $table->string('env_prefix');
        $table->timestamps();
        $table->unique(['app_instance_id', 'env_prefix']);
        $table->unique(['workspace_id', 'env_prefix']);
    });

    Schema::create('node_access', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('consumer_node_id')->constrained('nodes')->cascadeOnDelete();
        $table->foreignId('serving_node_id')->constrained('nodes')->cascadeOnDelete();
        $table->json('permissions');
        $table->json('custom_permissions');
        $table->timestamps();
        $table->unique(['consumer_node_id', 'serving_node_id']);
    });
}

function run_app_instance_vocabulary_cutover_migration(): void
{
    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Vocabulary cutover migration must expose up().');
    }

    $migration->up();
}

/**
 * @return list<array{from: string, table: string, to: string}>
 */
function sqlite_fk_targets(string $table): array
{
    return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->map(fn (object $row): array => [
            'from' => (string) $row->from,
            'table' => (string) $row->table,
            'to' => (string) $row->to,
        ])
        ->values()
        ->all();
}

/**
 * @return list<list<string>>
 */
function sqlite_index_column_sets(string $table): array
{
    $sets = [];

    foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
        $name = (string) $index->name;

        if (str_starts_with($name, 'sqlite_autoindex_')) {
            // Keep autoindexes; they still prove unique constraints after rename.
        }

        $columns = collect(DB::select("PRAGMA index_info('{$name}')"))
            ->sortBy(fn (object $row): int => (int) $row->seqno)
            ->map(fn (object $row): string => (string) $row->name)
            ->values()
            ->all();

        if ($columns !== []) {
            $sets[] = $columns;
        }
    }

    return $sets;
}
