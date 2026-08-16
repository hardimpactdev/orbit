<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills every instance-backed route and preserves routes without instance ownership', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'preview.docs.test');
        insertProxyRouteOwnershipWorkspace(20, 10);

        insertHistoricalProxyRoute(100, 'preview.docs.test', 'app', 'app', 1, null, [
            'instance' => [
                'id' => 11,
                'name' => 'preview',
                'selector' => 'docs.preview',
                'domain' => 'preview.docs.test',
            ],
            'target' => ['type' => 'instance', 'value' => 'docs.preview'],
        ]);
        insertHistoricalProxyRoute(101, 'feature.docs.test', 'workspace', 'workspace', 1, 20);
        insertHistoricalProxyRoute(102, 'analytics.docs.test', 'app-analytics', 'proxy', 1, null, [
            'instance_id' => 11,
        ]);
        insertHistoricalProxyRoute(103, 'ws.docs.test', 'app-websocket', 'proxy', 1, null, [
            'instance_id' => 11,
        ]);
        insertHistoricalProxyRoute(104, 'custom.test', 'custom', 'proxy', null, null, [
            'upstream' => 'http://127.0.0.1:8080',
        ]);

        proxyRouteInstanceOwnershipMigration()->up();

        expect(DB::table('proxy_routes')->orderBy('id')->pluck('instance_id', 'id')->all())
            ->toBe([
                100 => 11,
                101 => 10,
                102 => 11,
                103 => 11,
                104 => null,
            ])
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeTrue()
            ->and(proxyRouteInstanceOwnershipForeignKeys())
            ->toContain([
                'foreign_table' => 'instances',
                'foreign_column' => 'id',
                'local_column' => 'instance_id',
                'on_delete' => 'set null',
            ])
            ->and(collect(Schema::getIndexes('proxy_routes'))->pluck('name')->all())
            ->toContain('proxy_routes_instance_id_owner_type_index');

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->not
            ->toThrow(Throwable::class)
            ->and(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBe(11);
    });
});

it('fails before schema mutation when an instance-backed route has no possible owner', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='app' has no Instance candidates for app_id=1",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails before schema mutation when a configured instance owner was deleted or is missing', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 999],
        ]);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='app' identifies missing instance_id=999",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails when duplicate instance domains make legacy route ownership ambiguous', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'shared.docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'shared.docs.test');
        insertHistoricalProxyRoute(100, 'shared.docs.test', 'app', 'app', 1);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='shared.docs.test' owner_type='app' has ambiguous domain ownership candidates [10, 11]",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails when configured route identities point at competing instances', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'preview.docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1, null, [
            'instance' => [
                'id' => 10,
                'name' => 'preview',
                'selector' => 'docs.preview',
            ],
        ]);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='app' has competing instance identities: instance.id=>10, instance.name=>11, instance.selector=>11",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails when a workspace route points at a deleted or missing workspace owner', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        DB::statement('PRAGMA foreign_keys = OFF');
        insertHistoricalProxyRoute(100, 'feature.docs.test', 'workspace', 'workspace', 1, 999);
        DB::statement('PRAGMA foreign_keys = ON');

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='feature.docs.test' owner_type='workspace' identifies missing workspace_id=999",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails closed on rerun when an owner was deleted after a successful backfill', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 10],
        ]);

        proxyRouteInstanceOwnershipMigration()->up();
        DB::table('instances')->where('id', 10)->delete();

        expect(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBeNull()
            ->and(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='app' has null instance_id in an already-migrated schema",
            );
    });
});

function withHistoricalProxyRouteOwnershipSchema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'proxy_route_instance_ownership_history';

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

    Schema::create('nodes', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
    });
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
    });
    Schema::create('instances', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->string('name');
        $table->string('driver');
        $table->json('driver_config')->nullable();
    });
    Schema::create('workspaces', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
        $table->foreignId('instance_id')->constrained('instances')->cascadeOnDelete();
        $table->string('name');
    });
    Schema::create('proxy_routes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes')->restrictOnDelete();
        $table->string('domain')->unique();
        $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
        $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        $table->string('owner_type');
        $table->string('kind');
        $table->string('source_hash', 64);
        $table->json('config')->nullable();
        $table->timestamps();
    });

    DB::table('nodes')->insert(['id' => 1, 'name' => 'app-dev-1']);

    try {
        $callback();
    } finally {
        Schema::connection($connection)->dropAllTables();
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function insertProxyRouteOwnershipApp(): void
{
    DB::table('apps')->insert(['id' => 1, 'name' => 'docs']);
}

function insertProxyRouteOwnershipInstance(int $id, string $name, string $domain): void
{
    DB::table('instances')->insert([
        'id' => $id,
        'app_id' => 1,
        'name' => $name,
        'driver' => 'orbit',
        'driver_config' => json_encode([
            'type' => 'orbit_app_instance_driver_config',
            'data' => [
                'node_id' => 1,
                'node' => 'app-dev-1',
                'path' => "/srv/docs-{$name}",
                'document_root' => 'public',
                'domain' => $domain,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
}

function insertProxyRouteOwnershipWorkspace(int $id, int $instanceId): void
{
    DB::table('workspaces')->insert([
        'id' => $id,
        'app_id' => 1,
        'instance_id' => $instanceId,
        'name' => 'feature',
    ]);
}

/**
 * @param array<string, mixed> $config
 * @mago-expect lint:excessive-parameter-list
 */
function insertHistoricalProxyRoute(
    int $id,
    string $domain,
    string $ownerType,
    string $kind,
    ?int $appId,
    ?int $workspaceId = null,
    array $config = [],
): void {
    DB::table('proxy_routes')->insert([
        'id' => $id,
        'node_id' => 1,
        'domain' => $domain,
        'app_id' => $appId,
        'workspace_id' => $workspaceId,
        'owner_type' => $ownerType,
        'kind' => $kind,
        'source_hash' => str_repeat('a', 64),
        'config' => json_encode($config, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return list<array{foreign_table: string, foreign_column: string, local_column: string, on_delete: string}> */
function proxyRouteInstanceOwnershipForeignKeys(): array
{
    return array_map(
        static fn (array $foreignKey): array => [
            'foreign_table' => (string) $foreignKey['foreign_table'],
            'foreign_column' => (string) $foreignKey['foreign_columns'][0],
            'local_column' => (string) $foreignKey['columns'][0],
            'on_delete' => mb_strtolower((string) $foreignKey['on_delete']),
        ],
        Schema::getForeignKeys('proxy_routes'),
    );
}

function proxyRouteInstanceOwnershipMigration(): Migration
{
    $paths = glob(database_path('migrations/*_persist_proxy_route_instance_ownership.php'));

    if (! is_array($paths) || count($paths) !== 1) {
        throw new RuntimeException('Expected one persist proxy route instance ownership migration.');
    }

    $migration = require $paths[0];

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected proxy route instance ownership migration instance.');
    }

    return $migration;
}
