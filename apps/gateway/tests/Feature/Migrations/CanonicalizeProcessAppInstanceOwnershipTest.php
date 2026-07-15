<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('leaves node-owned process events without app instance ownership', function (): void {
    with_historical_process_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert(['id' => 1, 'name' => 'database-1']);
        DB::table('processes')->insert([
            'id' => 1,
            'node_id' => 1,
            'owner_type' => App\Models\Node::class,
            'owner_id' => 1,
            'name' => 'redis',
            'command' => 'redis-server',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('process_events')->insert([
            'id' => 1,
            'event' => 'started',
            'event_id' => 'evt-node-started',
            'process_id' => 1,
            'app_id' => null,
            'workspace_id' => null,
            'node_id' => 1,
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        process_app_instance_ownership_migration()->up();

        expect(Schema::hasColumn('processes', 'app_instance_id'))
            ->toBeTrue()
            ->and(Schema::hasColumn('process_events', 'app_instance_id'))
            ->toBeTrue()
            ->and(DB::table('process_events')->where('id', 1)->value('app_instance_id'))
            ->toBeNull();

        expect(fn (): bool => DB::table('processes')->insert([
            'id' => 2,
            'node_id' => 1,
            'owner_type' => App\Models\Node::class,
            'owner_id' => 1,
            'app_instance_id' => null,
            'name' => 'redis',
            'command' => 'redis-server --port 6380',
            'sort_order' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]))
            ->toThrow(QueryException::class);
    });
});

it('backfills app and workspace process ownership without replicating definitions', function (): void {
    with_historical_process_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert([
            ['id' => 1, 'name' => 'app-development'],
            ['id' => 2, 'name' => 'app-production'],
        ]);
        DB::table('apps')->insert([
            'id' => 1,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/srv/docs',
        ]);
        DB::table('app_instances')->insert([
            historical_process_instance(1, 1, 'development', 1, '/srv/docs'),
            historical_process_instance(2, 1, 'production', 2, '/srv/docs-production'),
        ]);
        DB::table('workspaces')->insert([
            'id' => 1,
            'app_id' => 1,
            'app_instance_id' => 2,
        ]);
        DB::table('processes')->insert([
            historical_process(1, 1, App\Models\App::class, 1, 'queue', $now),
            historical_process(2, 2, App\Models\Workspace::class, 1, 'vite', $now),
        ]);
        DB::table('process_events')->insert([
            historical_process_event(1, 'evt-app', 1, 1, null, 1, $now),
            historical_process_event(2, 'evt-workspace', 2, 1, 1, 2, $now),
        ]);

        process_app_instance_ownership_migration()->up();

        expect(DB::table('processes')->where('id', 1)->value('app_instance_id'))
            ->toBe(1)
            ->and(DB::table('processes')->where('id', 2)->value('app_instance_id'))
            ->toBe(2)
            ->and(DB::table('process_events')->where('id', 1)->value('app_instance_id'))
            ->toBe(1)
            ->and(DB::table('process_events')->where('id', 2)->value('app_instance_id'))
            ->toBe(2)
            ->and(DB::table('processes')->count())
            ->toBe(2);
    });
});

it('stops before schema mutation when historical app process ownership is ambiguous', function (): void {
    with_historical_process_ownership_schema(function (): void {
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
            historical_process_instance(1, 1, 'development', 2, '/srv/docs-development'),
            historical_process_instance(2, 1, 'production', 3, '/srv/docs-production'),
        ]);
        DB::table('processes')->insert(
            historical_process(1, 1, App\Models\App::class, 1, 'queue', $now),
        );

        expect(fn (): mixed => process_app_instance_ownership_migration()->up())
            ->toThrow(RuntimeException::class, 'requires manual assignment before migration')
            ->and(Schema::hasColumn('processes', 'app_instance_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('process_events', 'app_instance_id'))
            ->toBeFalse()
            ->and(DB::table('processes')->count())
            ->toBe(1);
    });
});

it('stops before schema mutation when an unlinked historical app event is ambiguous', function (): void {
    with_historical_process_ownership_schema(function (): void {
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
            historical_process_instance(1, 1, 'development', 2, '/srv/docs-development'),
            historical_process_instance(2, 1, 'production', 3, '/srv/docs-production'),
        ]);
        DB::table('process_events')->insert(
            historical_process_event(1, 'evt-app', null, 1, null, 1, $now),
        );

        expect(fn (): mixed => process_app_instance_ownership_migration()->up())
            ->toThrow(RuntimeException::class, 'process_event_id=1')
            ->and(Schema::hasColumn('processes', 'app_instance_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('process_events', 'app_instance_id'))
            ->toBeFalse();
    });
});

it('stops before schema mutation when historical event ownership candidates conflict', function (): void {
    with_historical_process_ownership_schema(function (): void {
        $now = now();
        DB::table('nodes')->insert([
            ['id' => 1, 'name' => 'development'],
            ['id' => 2, 'name' => 'production'],
        ]);
        DB::table('apps')->insert([
            'id' => 1,
            'node_id' => 1,
            'name' => 'docs',
            'path' => '/srv/docs-development',
        ]);
        DB::table('app_instances')->insert([
            historical_process_instance(1, 1, 'development', 1, '/srv/docs-development'),
            historical_process_instance(2, 1, 'production', 2, '/srv/docs-production'),
        ]);
        DB::table('workspaces')->insert([
            'id' => 1,
            'app_id' => 1,
            'app_instance_id' => 2,
        ]);
        DB::table('processes')->insert(
            historical_process(1, 1, App\Models\App::class, 1, 'queue', $now),
        );
        DB::table('process_events')->insert(
            historical_process_event(1, 'evt-conflict', 1, 1, 1, 1, $now),
        );

        expect(fn (): mixed => process_app_instance_ownership_migration()->up())
            ->toThrow(RuntimeException::class, 'process_event_id=1')
            ->and(Schema::hasColumn('processes', 'app_instance_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn('process_events', 'app_instance_id'))
            ->toBeFalse();
    });
});

function with_historical_process_ownership_schema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'process_ownership_migration_history';

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
        create_historical_process_ownership_schema();
        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function create_historical_process_ownership_schema(): void
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
    Schema::create('workspaces', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
    });
    Schema::create('processes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
        $table->morphs('owner');
        $table->string('name');
        $table->text('command');
        $table->unsignedInteger('sort_order');
        $table->timestamps();
        $table->unique(['owner_type', 'owner_id', 'name']);
    });
    Schema::create('process_events', function (Blueprint $table): void {
        $table->id();
        $table->string('event');
        $table->string('event_id')->unique();
        $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
        $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
        $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
        $table->timestamp('recorded_at');
        $table->timestamps();
    });
}

function process_app_instance_ownership_migration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_07_15_000000_canonicalize_process_app_instance_ownership.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected process app-instance ownership migration instance.');
    }

    return $migration;
}

/**
 * @return array<string, mixed>
 */
function historical_process_instance(
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

/**
 * @return array<string, mixed>
 *
 * @mago-expect lint:excessive-parameter-list
 */
function historical_process(
    int $id,
    int $nodeId,
    string $ownerType,
    int $ownerId,
    string $name,
    mixed $now,
): array {
    return [
        'id' => $id,
        'node_id' => $nodeId,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'name' => $name,
        'command' => 'php artisan '.$name,
        'sort_order' => $id,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

/**
 * @return array<string, mixed>
 *
 * @mago-expect lint:excessive-parameter-list
 */
function historical_process_event(
    int $id,
    string $eventId,
    ?int $processId,
    ?int $appId,
    ?int $workspaceId,
    int $nodeId,
    mixed $now,
): array {
    return [
        'id' => $id,
        'event' => 'started',
        'event_id' => $eventId,
        'process_id' => $processId,
        'app_id' => $appId,
        'workspace_id' => $workspaceId,
        'node_id' => $nodeId,
        'recorded_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
