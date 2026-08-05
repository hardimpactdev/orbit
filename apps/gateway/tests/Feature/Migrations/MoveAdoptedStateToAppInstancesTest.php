<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the immutable 2026-07-20 add_adopted_to_app_instances_table step.
 * Uses a pre-cutover app_instances fixture — not the final instances schema.
 */
it('backfills project adoption state onto every existing concrete instance', function (): void {
    with_historical_adopted_schema(function (): void {
        $now = now();

        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'adopted' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_instances')->insert([
            [
                'id' => 10,
                'app_id' => 1,
                'name' => 'development',
                'driver' => 'orbit',
                'driver_config' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 11,
                'app_id' => 1,
                'name' => 'production',
                'driver' => 'orbit',
                'driver_config' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration = require
            database_path(
                'migrations/2026_07_20_100000_add_adopted_to_app_instances_table.php',
            );

        expect($migration)->toBeInstanceOf(Migration::class);

        $migration->up();

        expect(Schema::hasColumn('app_instances', 'adopted'))
            ->toBeTrue()
            ->and(
                DB::table('app_instances')
                    ->whereIn('id', [10, 11])
                    ->orderBy('id')
                    ->pluck('adopted')
                    ->all(),
            )
            ->toBe([1, 1]);
    });
});

function with_historical_adopted_schema(Closure $callback): void
{
    $connection = 'historical_adopted_migration';

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

    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('adopted')->default(false);
        $table->timestamps();
    });

    Schema::create('app_instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->string('driver');
        $table->json('driver_config');
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
