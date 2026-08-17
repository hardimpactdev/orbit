<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('canonicalizes legacy app ownership onto instance and remaps keys atomically', function (): void {
    withClosedScheduleOwnerSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert([
            ['id' => 1, 'name' => 'app-development'],
            ['id' => 2, 'name' => 'gateway'],
        ]);
        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
        ]);
        DB::table('instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'development',
        ]);
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'schedule_key' => 'app:docs.development:laravel-scheduler',
                'name' => 'laravel-scheduler',
                'scope' => 'app',
                'app_id' => 1,
                'instance_id' => 10,
                'node_id' => null,
                'target_name' => 'docs',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'schedule_key' => 'node:app-development:heartbeat',
                'name' => 'heartbeat',
                'scope' => 'node',
                'app_id' => 1,
                'instance_id' => null,
                'node_id' => 1,
                'target_name' => 'stale-node-label',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'schedule_key' => 'orbit:gateway:maintenance',
                'name' => 'maintenance',
                'scope' => 'orbit',
                'app_id' => 1,
                'instance_id' => null,
                'node_id' => null,
                'target_name' => 'stale-orbit-label',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('schedule_runs')->insert([
            [
                'id' => 1,
                'schedule_key' => 'app:docs.development:laravel-scheduler',
                'target_name' => null,
            ],
            [
                'id' => 2,
                'schedule_key' => 'node:app-development:heartbeat',
                'target_name' => null,
            ],
        ]);
        DB::table('schedule_locks')->insert([
            [
                'id' => 1,
                'schedule_key' => 'app:docs.development:laravel-scheduler',
            ],
        ]);

        constrainScheduleOwnerInvariantMigration()->up();

        expect(Schema::hasColumn('schedules', 'app_id'))
            ->toBeFalse()
            ->and(DB::table('schedules')->where('id', 1)->value('scope'))
            ->toBe('instance')
            ->and(DB::table('schedules')->where('id', 1)->value('instance_id'))
            ->toBe(10)
            ->and(DB::table('schedules')->where('id', 1)->value('node_id'))
            ->toBeNull()
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('instance:docs.development:laravel-scheduler')
            ->and(DB::table('schedules')->where('id', 1)->value('target_name'))
            ->toBe('docs.development')
            ->and(DB::table('schedules')->where('id', 2)->value('scope'))
            ->toBe('node')
            ->and(DB::table('schedules')->where('id', 2)->value('target_name'))
            ->toBe('app-development')
            ->and(DB::table('schedules')->where('id', 3)->value('scope'))
            ->toBe('orbit')
            ->and(DB::table('schedules')->where('id', 3)->value('target_name'))
            ->toBe('gateway')
            ->and(DB::table('schedule_runs')->where('id', 1)->value('schedule_key'))
            ->toBe('instance:docs.development:laravel-scheduler')
            ->and(DB::table('schedule_runs')->where('id', 1)->value('target_name'))
            ->toBe('docs.development')
            ->and(DB::table('schedule_locks')->where('id', 1)->value('schedule_key'))
            ->toBe('instance:docs.development:laravel-scheduler');
    });
});

it('deletes orphaned schedules whose owner is already gone and keeps historical runs', function (): void {
    withClosedScheduleOwnerSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'app-development']);
        DB::table('apps')->insert(['id' => 1, 'name' => 'docs']);
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs.missing:nightly',
            'name' => 'nightly',
            'scope' => 'app',
            'app_id' => 1,
            'instance_id' => 99,
            'node_id' => null,
            'target_name' => 'docs.missing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('schedule_runs')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs.missing:nightly',
            'target_name' => null,
        ]);
        DB::table('schedule_locks')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs.missing:nightly',
        ]);

        constrainScheduleOwnerInvariantMigration()->up();

        expect(DB::table('schedules')->count())
            ->toBe(0)
            ->and(DB::table('schedule_runs')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs.missing:nightly')
            ->and(DB::table('schedule_runs')->where('id', 1)->value('target_name'))
            ->toBe('docs.missing')
            ->and(DB::table('schedule_locks')->count())
            ->toBe(0);
    });
});

it('stops before mutation when a legacy row has two live owners', function (): void {
    withClosedScheduleOwnerSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'app-1']);
        DB::table('apps')->insert(['id' => 1, 'name' => 'docs']);
        DB::table('instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'development',
        ]);
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'app:docs.development:nightly',
            'name' => 'nightly',
            'scope' => 'app',
            'app_id' => 1,
            'instance_id' => 10,
            'node_id' => 1,
            'target_name' => 'docs.development',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn (): mixed => constrainScheduleOwnerInvariantMigration()->up())
            ->toThrow(RuntimeException::class, 'schedule_id=1')
            ->and(Schema::hasColumn('schedules', 'app_id'))
            ->toBeTrue()
            ->and(DB::table('schedules')->where('id', 1)->value('schedule_key'))
            ->toBe('app:docs.development:nightly')
            ->and(DB::table('schedules')->where('id', 1)->value('scope'))
            ->toBe('app');
    });
});

it('rejects contradictory owners at the database after canonicalization', function (): void {
    withClosedScheduleOwnerSchema(function (): void {
        $now = now();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'app-1']);
        DB::table('schedules')->insert([
            'id' => 1,
            'schedule_key' => 'orbit:gateway:maintenance',
            'name' => 'maintenance',
            'scope' => 'orbit',
            'app_id' => null,
            'instance_id' => null,
            'node_id' => null,
            'target_name' => 'gateway',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        constrainScheduleOwnerInvariantMigration()->up();

        expect(fn (): mixed => DB::table('schedules')->where('id', 1)->update(['node_id' => 1]))
            ->toThrow(QueryException::class);
    });
});

function withClosedScheduleOwnerSchema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'schedule_owner_invariant_migration';

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        createClosedScheduleOwnerLegacySchema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function createClosedScheduleOwnerLegacySchema(): void
{
    Schema::create('nodes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('instances', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('app_id');
        $table->string('name');
    });
    Schema::create('schedules', function (Blueprint $table): void {
        $table->id();
        $table->string('schedule_key')->unique();
        $table->string('name');
        $table->string('scope');
        $table->unsignedBigInteger('app_id')->nullable();
        $table->unsignedBigInteger('instance_id')->nullable();
        $table->unsignedBigInteger('node_id')->nullable();
        $table->string('target_name');
        $table->timestamps();
    });
    Schema::create('schedule_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('schedule_key');
        $table->string('target_name')->nullable();
    });
    Schema::create('schedule_locks', function (Blueprint $table): void {
        $table->id();
        $table->string('schedule_key');
    });
}

function constrainScheduleOwnerInvariantMigration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_08_17_200000_constrain_schedule_owner_invariant.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected closed schedule-owner invariant migration instance.');
    }

    return $migration;
}
