<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the fail-closed 2026-08-16 drop_app_placement_shadow_columns step:
 * every App must be representable by a concrete Orbit Instance carrying its
 * legacy placement before the six shadow columns are dropped. Uses a
 * pre-cutover apps/instances fixture — not the final schema.
 */
it('backfills a concrete Orbit instance for an app with no matching instance, then drops the shadow columns', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 7, 'name' => 'app-dev-1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'node_id' => 7,
            'environment' => 'production',
            'domain' => 'docs.example.test',
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.4',
            'adopted' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        drop_app_placement_shadow_columns_migration()->up();

        // Columns are gone.
        foreach (['node_id', 'environment', 'domain', 'path', 'document_root', 'adopted'] as $column) {
            expect(Schema::hasColumn('apps', $column))->toBeFalse();
        }

        // A concrete Orbit instance now carries the legacy placement + snapshots.
        $instance = DB::table('instances')->where('app_id', 1)->first();

        expect($instance)
            ->not
            ->toBeNull()
            ->and($instance->name)
            ->toBe('production')
            ->and($instance->driver)
            ->toBe('orbit')
            ->and((bool) $instance->adopted)
            ->toBeTrue()
            ->and($instance->php_version)
            ->toBe('8.4');

        $config = json_decode((string) $instance->driver_config, true);

        expect($config['type'])
            ->toBe('orbit_instance_driver_config')
            ->and($config['data']['node_id'])
            ->toBe(7)
            ->and($config['data']['node'])
            ->toBe('app-dev-1')
            ->and($config['data']['path'])
            ->toBe('/home/orbit/apps/docs')
            ->and($config['data']['document_root'])
            ->toBe('public')
            ->and($config['data']['domain'])
            ->toBe('docs.example.test');
    });
});

it('writes frozen empty runtime requirements when backfilling a missing instance', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 7, 'name' => 'app-dev-1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'node_id' => 7,
            'environment' => 'production',
            'domain' => 'docs.example.test',
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.4',
            'adopted' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        drop_app_placement_shadow_columns_migration()->up();

        expect((string) DB::table('instances')->where('app_id', 1)->value('runtime_requirements'))
            ->toBe('{"php_extensions":[]}');
    });
});

it('leaves an app untouched when a concrete Orbit instance already carries its placement', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 7, 'name' => 'app-dev-1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'node_id' => 7,
            'environment' => 'development',
            'domain' => null,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'development',
            'driver' => 'orbit',
            'php_version' => '8.5',
            'adopted' => false,
            'driver_config' => json_encode([
                'type' => 'orbit_instance_driver_config',
                'data' => [
                    'node_id' => 7,
                    'node' => 'app-dev-1',
                    'path' => '/home/orbit/apps/docs',
                    'document_root' => 'public',
                    'domain' => null,
                ],
            ], JSON_THROW_ON_ERROR),
            'runtime_requirements' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        drop_app_placement_shadow_columns_migration()->up();

        expect(Schema::hasColumn('apps', 'node_id'))
            ->toBeFalse()
            // No duplicate instance was created; the matching one is reused.
            ->and(DB::table('instances')->where('app_id', 1)->count())
            ->toBe(1);
    });
});

it('fails closed and leaves the shadow columns intact when an app has no legacy node placement', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        // No node_id → the app cannot be represented by a concrete instance.
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'node_id' => null,
            'environment' => 'development',
            'domain' => null,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn (): mixed => drop_app_placement_shadow_columns_migration()->up())
            ->toThrow(RuntimeException::class, 'legacy node_id missing');

        // Fail-closed: nothing was dropped.
        expect(Schema::hasColumn('apps', 'node_id'))
            ->toBeTrue()
            ->and(Schema::hasColumn('apps', 'environment'))
            ->toBeTrue()
            ->and(DB::table('instances')->where('app_id', 1)->count())
            ->toBe(0);
    });
});

it('fails closed when a name-conflicting instance already exists with divergent placement', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 7, 'name' => 'app-dev-1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'node_id' => 7,
            'environment' => 'production',
            'domain' => null,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Same name as the would-be backfill ('production'), but different path:
        // divergent placement the migration must refuse to overwrite.
        DB::table('instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'production',
            'driver' => 'orbit',
            'php_version' => '8.5',
            'adopted' => false,
            'driver_config' => json_encode([
                'type' => 'orbit_instance_driver_config',
                'data' => [
                    'node_id' => 7,
                    'node' => 'app-dev-1',
                    'path' => '/srv/other/path',
                    'document_root' => 'public',
                    'domain' => null,
                ],
            ], JSON_THROW_ON_ERROR),
            'runtime_requirements' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn (): mixed => drop_app_placement_shadow_columns_migration()->up())
            ->toThrow(RuntimeException::class, "instance 'production' already exists with divergent placement");

        expect(Schema::hasColumn('apps', 'node_id'))->toBeTrue();
    });
});

it('matches instances left behind by the 2026-08-05 slug rename, as on a real gateway database', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 4, 'name' => 'NMBP', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('apps')->insert([
            'id' => 11,
            'name' => 'platform11',
            'node_id' => 4,
            'environment' => 'development',
            'domain' => 'platform11.nmbp',
            'path' => '/Users/nckrtl/apps/platform11',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Pre-rename row exactly as it existed before 2026-08-05.
        DB::table('instances')->insert([
            'id' => 10,
            'app_id' => 11,
            'name' => 'development',
            'driver' => 'orbit',
            'php_version' => '8.5',
            'adopted' => false,
            'driver_config' => json_encode([
                'type' => 'orbit_app_instance_driver_config',
                'data' => [
                    'node_id' => 4,
                    'node' => 'NMBP',
                    'path' => '/Users/nckrtl/apps/platform11',
                    'document_root' => 'public',
                    'domain' => 'platform11.nmbp',
                ],
            ], JSON_THROW_ON_ERROR),
            'runtime_requirements' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Replay the exact rename the live database went through on 2026-08-05,
        // then the shadow-column drop: the composed chain must match the
        // renamed slug instead of reporting divergent placement.
        rename_app_instances_migration()->up();

        drop_app_placement_shadow_columns_migration()->up();

        expect(Schema::hasColumn('apps', 'node_id'))
            ->toBeFalse()
            ->and(DB::table('instances')->where('app_id', 11)->count())
            ->toBe(1)
            ->and(
                json_decode((string) DB::table('instances')->where('app_id', 11)->value('driver_config'), true)['type'],
            )
            ->toBe('orbit_instance_driver_config');
    });
});

it('is idempotent when the shadow columns are already gone', function (): void {
    with_historical_placement_shadow_schema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 7, 'name' => 'app-dev-1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'node_id' => 7,
            'environment' => 'development',
            'domain' => null,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // First run drops the columns; a second run must be a harmless no-op.
        drop_app_placement_shadow_columns_migration()->up();

        expect(fn (): mixed => drop_app_placement_shadow_columns_migration()->up())->not->toThrow(Throwable::class);

        expect(Schema::hasColumn('apps', 'node_id'))
            ->toBeFalse()
            ->and(DB::table('instances')->where('app_id', 1)->count())
            ->toBe(1);
    });
});

function with_historical_placement_shadow_schema(Closure $callback): void
{
    $connection = 'historical_placement_shadow_migration';

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
    Schema::connection($connection)->dropAllTables();

    Schema::create('nodes', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->timestamps();
    });

    // Mirror the pre-cutover apps schema: the six shadow columns plus the
    // node_id foreign key and its two composite indexes the migration drops.
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->foreignId('node_id')->nullable()->constrained('nodes')->restrictOnDelete();
        $table->string('environment')->default('development');
        $table->string('domain')->nullable()->unique();
        $table->string('path')->nullable();
        $table->string('document_root')->default('public');
        $table->string('repository')->nullable();
        $table->string('php_version')->default('8.5');
        $table->boolean('adopted')->default(false);
        $table->timestamps();

        $table->index(['node_id', 'name']);
        $table->index(['node_id', 'environment']);
    });

    Schema::create('instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->string('driver');
        $table->string('php_version')->nullable();
        $table->boolean('adopted')->default(false);
        $table->json('driver_config');
        $table->json('runtime_requirements')->nullable();
        $table->timestamps();
    });

    try {
        $callback();
    } finally {
        Schema::connection($connection)->dropAllTables();
        DB::purge($connection);
        DB::setDefaultConnection(config('database.default'));
    }
}

function rename_app_instances_migration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected rename app instances migration instance.');
    }

    return $migration;
}

function drop_app_placement_shadow_columns_migration(): Migration
{
    $migration = require database_path('migrations/2026_08_16_120000_drop_app_placement_shadow_columns.php');

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected drop app placement shadow columns migration instance.');
    }

    return $migration;
}
