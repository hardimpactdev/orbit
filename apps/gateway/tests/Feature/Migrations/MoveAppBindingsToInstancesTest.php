<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills both legacy bindings when the app has one instance', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        insertHistoricalBindingApp(instanceCount: 1);
        insertHistoricalBindings();

        bindingInstanceOwnershipMigration()->up();

        expect(Schema::hasColumn('app_websocket_bindings', 'instance_id'))
            ->toBeTrue()
            ->and(Schema::hasColumn('app_websocket_bindings', 'app_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_analytics_bindings', 'instance_id'))
            ->toBeTrue()
            ->and(Schema::hasColumn('app_analytics_bindings', 'app_id'))
            ->toBeFalse()
            ->and(DB::table('app_websocket_bindings')->value('instance_id'))
            ->toBe(1)
            ->and(DB::table('app_analytics_bindings')->value('instance_id'))
            ->toBe(1);
    });
});

it('allows apps with zero or multiple instances when they have no legacy binding', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        insertHistoricalBindingApp(appId: 1, instanceCount: 0);
        insertHistoricalBindingApp(appId: 2, instanceCount: 2);

        bindingInstanceOwnershipMigration()->up();

        expect(Schema::hasColumn('app_websocket_bindings', 'instance_id'))
            ->toBeTrue()
            ->and(Schema::hasColumn('app_analytics_bindings', 'instance_id'))
            ->toBeTrue();
    });
});

it('stops before schema mutation when a legacy binding app has no instance', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        insertHistoricalBindingApp(instanceCount: 0);
        insertHistoricalBindings();

        expect(fn (): mixed => bindingInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, 'app_websocket_bindings#1 (app_id=1, instances=0)')
            ->and(Schema::hasColumn('app_websocket_bindings', 'instance_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_analytics_bindings', 'instance_id'))
            ->toBeFalse();
    });
});

it('stops before schema mutation when a legacy binding app has multiple instances', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        insertHistoricalBindingApp(instanceCount: 2);
        insertHistoricalBindings();

        expect(fn (): mixed => bindingInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, 'app_websocket_bindings#1 (app_id=1, instances=2)')
            ->and(Schema::hasColumn('app_websocket_bindings', 'instance_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_analytics_bindings', 'instance_id'))
            ->toBeFalse();
    });
});

it('tells operators to delete orphaned bindings whose app is gone', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        Schema::disableForeignKeyConstraints();
        DB::table('app_websocket_bindings')->insert(['id' => 1, 'app_id' => 99]);
        DB::table('app_analytics_bindings')->insert(['id' => 1, 'app_id' => 99]);
        Schema::enableForeignKeyConstraints();

        try {
            bindingInstanceOwnershipMigration()->up();
            throw new RuntimeException('Expected orphaned binding migration to abort.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('Orphaned bindings whose apps are gone must be deleted')
                ->toContain('app_websocket_bindings#1 (app_id=99, instances=0)')
                ->toContain('Delete each orphaned binding, then rerun migrations.')
                ->not->toContain('Assign each legacy binding to one concrete instance');
        }

        expect(Schema::hasColumn('app_websocket_bindings', 'instance_id'))->toBeFalse();
    });
});

it('refuses rollback instead of silently desyncing the already-migrated binding schema', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        insertHistoricalBindingApp(instanceCount: 1);
        insertHistoricalBindings();
        bindingInstanceOwnershipMigration()->up();

        expect(fn (): mixed => bindingInstanceOwnershipMigration()->down())
            ->toThrow(RuntimeException::class, 'irreversible')
            ->and(Schema::hasColumn('app_websocket_bindings', 'app_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('app_websocket_bindings', 'instance_id'))
            ->toBeTrue()
            ->and(DB::table('app_websocket_bindings')->value('instance_id'))
            ->toBe(1);
    });
});

it('no-ops when binding tables already use instance_id without app_id', function (): void {
    withHistoricalBindingOwnershipSchema(function (): void {
        insertHistoricalBindingApp(instanceCount: 1);
        insertHistoricalBindings();
        bindingInstanceOwnershipMigration()->up();

        expect(fn (): mixed => bindingInstanceOwnershipMigration()->up())
            ->not
            ->toThrow(Throwable::class)
            ->and(Schema::hasColumn('app_websocket_bindings', 'app_id'))
            ->toBeFalse()
            ->and(DB::table('app_websocket_bindings')->value('instance_id'))
            ->toBe(1)
            ->and(DB::table('app_analytics_bindings')->value('instance_id'))
            ->toBe(1);
    });
});

function withHistoricalBindingOwnershipSchema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'binding_ownership_migration_history';

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
        createHistoricalBindingOwnershipSchema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function createHistoricalBindingOwnershipSchema(): void
{
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
    });
    Schema::create('app_websocket_bindings', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->unique()->constrained('apps')->cascadeOnDelete();
    });
    Schema::create('app_analytics_bindings', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->unique()->constrained('apps')->cascadeOnDelete();
    });
}

function insertHistoricalBindingApp(int $appId = 1, int $instanceCount = 1): void
{
    DB::table('apps')->insert(['id' => $appId, 'name' => "app-{$appId}"]);

    foreach (range(1, $instanceCount) as $offset) {
        if ($instanceCount === 0) {
            break;
        }

        DB::table('instances')->insert([
            'id' => (($appId - 1) * 10) + $offset,
            'app_id' => $appId,
            'name' => "instance-{$offset}",
        ]);
    }
}

function insertHistoricalBindings(): void
{
    DB::table('app_websocket_bindings')->insert(['id' => 1, 'app_id' => 1]);
    DB::table('app_analytics_bindings')->insert(['id' => 1, 'app_id' => 1]);
}

function bindingInstanceOwnershipMigration(): Migration
{
    $migration = require database_path('migrations/2026_08_15_124510_move_app_bindings_to_instances.php');

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected binding instance ownership migration instance.');
    }

    return $migration;
}
