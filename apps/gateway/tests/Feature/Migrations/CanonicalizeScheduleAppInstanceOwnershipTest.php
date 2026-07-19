<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills an unambiguous app schedule to one concrete app instance', function (): void {
    withHistoricalScheduleOwnershipSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'app-development']);
        DB::table('apps')->insert([
            'id' => 1,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/srv/docs',
        ]);
        DB::table('app_instances')->insert(
            historicalScheduleInstance(1, 1, 'development', 1, '/srv/docs'),
        );
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs:laravel-scheduler',
            'name' => 'laravel-scheduler',
            'scope' => 'app',
            'app_id' => 1,
            'node_id' => null,
            'target_name' => 'docs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('schedule_runs')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs:laravel-scheduler',
        ]);
        DB::table('schedule_locks')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs:laravel-scheduler',
        ]);

        scheduleAppInstanceOwnershipMigration()->up();

        expect(Schema::hasColumn('schedules', 'app_instance_id'))
            ->toBeTrue()
            ->and(DB::table('schedules')->where('id', 1)->value('app_instance_id'))
            ->toBe(1)
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs.development:laravel-scheduler')
            ->and(DB::table('schedules')->where('id', 1)->value('target_name'))
            ->toBe('docs.development')
            ->and(DB::table('schedule_runs')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs.development:laravel-scheduler')
            ->and(DB::table('schedule_locks')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs.development:laravel-scheduler');
    });
});

it('leaves node and orbit schedules without app instance ownership', function (): void {
    withHistoricalScheduleOwnershipSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'app-development']);
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'schedule_key' => 'node:app-development:heartbeat',
                'name' => 'heartbeat',
                'scope' => 'node',
                'app_id' => null,
                'node_id' => 1,
                'target_name' => 'app-development',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'schedule_key' => 'orbit:gateway:maintenance',
                'name' => 'maintenance',
                'scope' => 'orbit',
                'app_id' => null,
                'node_id' => null,
                'target_name' => 'gateway',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        scheduleAppInstanceOwnershipMigration()->up();

        expect(DB::table('schedules')->where('id', 1)->value('app_instance_id'))
            ->toBeNull()
            ->and(DB::table('schedules')->where('id', 2)->value('app_instance_id'))
            ->toBeNull();
    });
});

it('stops before schema mutation when historical app schedule ownership is ambiguous', function (): void {
    withHistoricalScheduleOwnershipSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert([
            ['id' => 1, 'name' => 'legacy'],
            ['id' => 2, 'name' => 'development'],
            ['id' => 3, 'name' => 'production'],
        ]);
        DB::table('apps')->insert([
            'id' => 1,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/srv/legacy-docs',
        ]);
        DB::table('app_instances')->insert([
            historicalScheduleInstance(1, 1, 'development', 2, '/srv/docs-development'),
            historicalScheduleInstance(2, 1, 'production', 3, '/srv/docs-production'),
        ]);
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs:laravel-scheduler',
            'name' => 'laravel-scheduler',
            'scope' => 'app',
            'app_id' => 1,
            'node_id' => null,
            'target_name' => 'docs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn (): mixed => scheduleAppInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, 'schedule_id=1')
            ->and(Schema::hasColumn('schedules', 'app_instance_id'))
            ->toBeFalse()
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs:laravel-scheduler');
    });
});

it('stops before schema mutation when an app schedule has no concrete instance', function (): void {
    withHistoricalScheduleOwnershipSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'legacy']);
        DB::table('apps')->insert([
            'id' => 1,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/srv/docs',
        ]);
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs:laravel-scheduler',
            'name' => 'laravel-scheduler',
            'scope' => 'app',
            'app_id' => 1,
            'node_id' => null,
            'target_name' => 'docs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn (): mixed => scheduleAppInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, 'schedule_id=1')
            ->and(Schema::hasColumn('schedules', 'app_instance_id'))
            ->toBeFalse()
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs:laravel-scheduler');
    });
});

it('does not use legacy logical placement defaults to choose between app instances', function (): void {
    withHistoricalScheduleOwnershipSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert([
            ['id' => 1, 'name' => 'legacy-default'],
            ['id' => 2, 'name' => 'production'],
        ]);
        DB::table('apps')->insert([
            'id' => 1,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/srv/docs',
        ]);
        DB::table('app_instances')->insert([
            historicalScheduleInstance(1, 1, 'legacy-default-match', 1, '/srv/docs'),
            historicalScheduleInstance(2, 1, 'production', 2, '/srv/docs-production'),
        ]);
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs:laravel-scheduler',
            'name' => 'laravel-scheduler',
            'scope' => 'app',
            'app_id' => 1,
            'node_id' => null,
            'target_name' => 'docs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn (): mixed => scheduleAppInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, 'schedule_id=1')
            ->and(Schema::hasColumn('schedules', 'app_instance_id'))
            ->toBeFalse()
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs:laravel-scheduler');
    });
});

function withHistoricalScheduleOwnershipSchema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'schedule_ownership_migration_history';

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
        createHistoricalScheduleOwnershipSchema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function createHistoricalScheduleOwnershipSchema(): void
{
    Schema::create('nodes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes');
        $table->string('name');
        $table->string('path');
    });
    Schema::create('app_instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->string('driver');
        $table->json('driver_config');
    });
    Schema::create('schedules', function (Blueprint $table): void {
        $table->id();
        $table->string('schedule_key')->unique();
        $table->string('name');
        $table->string('scope');
        $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
        $table->foreignId('node_id')->nullable()->constrained('nodes')->cascadeOnDelete();
        $table->string('target_name');
        $table->timestamps();
    });
    Schema::create('schedule_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('schedule_key');
    });
    Schema::create('schedule_locks', function (Blueprint $table): void {
        $table->id();
        $table->string('schedule_key');
    });
}

function scheduleAppInstanceOwnershipMigration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_07_19_010000_canonicalize_schedule_app_instance_ownership.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected schedule app-instance ownership migration instance.');
    }

    return $migration;
}

/**
 * @return array<string, mixed>
 */
function historicalScheduleInstance(
    int $id,
    int $appId,
    string $name,
    int $nodeId,
    string $path,
): array {
    return [
        'id' => $id,
        'app_id' => $appId,
        'name' => $name,
        'driver' => 'orbit',
        'driver_config' => json_encode([
            'type' => 'orbit_app_instance_driver_config',
            'data' => [
                'node_id' => $nodeId,
                'path' => $path,
            ],
        ], JSON_THROW_ON_ERROR),
    ];
}
