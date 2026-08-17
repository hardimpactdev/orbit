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

it('fails before schema mutation when an instance-backed owner has the wrong route kind', function (
    string $ownerType,
    string $kind,
    string $expectedKind,
): void {
    withHistoricalProxyRouteOwnershipSchema(function () use ($ownerType, $kind, $expectedKind): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');

        $workspaceId = null;

        if ($ownerType === 'workspace') {
            insertProxyRouteOwnershipWorkspace(20, 10);
            $workspaceId = 20;
        }

        insertHistoricalProxyRoute(100, 'docs.test', $ownerType, $kind, 1, $workspaceId);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='{$ownerType}' requires kind='{$expectedKind}' but found kind='{$kind}'",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
})->with([
    'app owner with proxy kind' => ['app', 'proxy', 'app'],
    'workspace owner with proxy kind' => ['workspace', 'proxy', 'workspace'],
    'analytics owner with app kind' => ['app-analytics', 'app', 'proxy'],
    'websocket owner with app kind' => ['app-websocket', 'app', 'proxy'],
]);

it('fails before schema mutation when route config contains malformed non-empty JSON', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1);
        DB::table('proxy_routes')->where('id', 100)->update(['config' => '{malformed']);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='app' has malformed config JSON",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails before schema mutation when instance driver config contains malformed non-empty JSON', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        DB::table('instances')->where('id', 10)->update(['driver_config' => '{malformed']);
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                'ProxyRoute instance ownership migration blocked by instances#10 has malformed driver_config JSON.',
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails before schema mutation when a public binding route has incomplete App or Instance identity', function (
    string $ownerType,
    string $invalidity,
    string $expectedMessage,
): void {
    withHistoricalProxyRouteOwnershipSchema(function () use ($ownerType, $invalidity, $expectedMessage): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');

        if ($invalidity === 'conflicting app') {
            DB::table('apps')->insert(['id' => 2, 'name' => 'store', 'runtime' => 'php']);
        }

        $workspaceId = null;

        if ($invalidity === 'workspace identity') {
            insertProxyRouteOwnershipWorkspace(20, 10);
            $workspaceId = 20;
        }

        $appId = match ($invalidity) {
            'missing app' => null,
            'conflicting app' => 2,
            default => 1,
        };
        $config = ['instance_id' => $invalidity === 'missing instance' ? 999 : 10];

        insertHistoricalProxyRoute(100, 'public.docs.test', $ownerType, 'proxy', $appId, $workspaceId, $config);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, $expectedMessage)
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
})->with([
    'analytics missing app' => ['app-analytics', 'missing app', 'has no app_id'],
    'analytics missing instance' => ['app-analytics', 'missing instance', 'identifies missing instance_id=999'],
    'analytics conflicting app' => ['app-analytics', 'conflicting app', 'has no Instance candidates for app_id=2'],
    'analytics workspace identity' => ['app-analytics', 'workspace identity', 'must not identify a workspace_id'],
    'websocket missing app' => ['app-websocket', 'missing app', 'has no app_id'],
    'websocket missing instance' => ['app-websocket', 'missing instance', 'identifies missing instance_id=999'],
    'websocket conflicting app' => ['app-websocket', 'conflicting app', 'has no Instance candidates for app_id=2'],
    'websocket workspace identity' => ['app-websocket', 'workspace identity', 'must not identify a workspace_id'],
]);

it('fails closed without mutating stray instance ownership on non-instance routes when rerun', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'custom.test', 'custom', 'proxy', null);

        proxyRouteInstanceOwnershipMigration()->up();
        DB::table('proxy_routes')->where('id', 100)->update(['instance_id' => 10]);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='custom.test' owner_type='custom' must not identify instance_id=10",
            )
            ->and(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBe(10);
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

it('fails before schema mutation when a configured ownership id is not a strict positive integer', function (
    string $ownerType,
    string $path,
    mixed $value,
): void {
    withHistoricalProxyRouteOwnershipSchema(function () use ($ownerType, $path, $value): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');

        $workspaceId = null;

        if ($ownerType === 'workspace') {
            insertProxyRouteOwnershipWorkspace(20, 10);
            $workspaceId = 20;
        }

        $config = $path === 'config.instance_id'
            ? ['instance_id' => $value]
            : ['instance' => ['id' => $value]];

        insertHistoricalProxyRoute(
            100,
            $ownerType === 'workspace' ? 'feature.docs.test' : 'docs.test',
            $ownerType,
            $ownerType === 'workspace' ? 'workspace' : 'app',
            1,
            $workspaceId,
            $config,
        );

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(RuntimeException::class, "configured ownership hint {$path} must be a positive integer")
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
})->with([
    'app direct decimal' => ['app', 'config.instance_id', 10.5],
    'app direct numeric string' => ['app', 'config.instance_id', '10'],
    'app direct zero' => ['app', 'config.instance_id', 0],
    'app direct negative' => ['app', 'config.instance_id', -10],
    'app direct whitespace' => ['app', 'config.instance_id', ' 10 '],
    'app direct junk' => ['app', 'config.instance_id', '10junk'],
    'app direct non-numeric' => ['app', 'config.instance_id', 'invalid'],
    'app nested decimal' => ['app', 'instance.id', 10.5],
    'app nested numeric string' => ['app', 'instance.id', '10'],
    'app nested zero' => ['app', 'instance.id', 0],
    'app nested negative' => ['app', 'instance.id', -10],
    'app nested whitespace' => ['app', 'instance.id', ' 10 '],
    'app nested junk' => ['app', 'instance.id', '10junk'],
    'app nested non-numeric' => ['app', 'instance.id', 'invalid'],
    'workspace direct decimal' => ['workspace', 'config.instance_id', 10.5],
    'workspace direct numeric string' => ['workspace', 'config.instance_id', '10'],
    'workspace direct zero' => ['workspace', 'config.instance_id', 0],
    'workspace direct negative' => ['workspace', 'config.instance_id', -10],
    'workspace direct whitespace' => ['workspace', 'config.instance_id', ' 10 '],
    'workspace direct junk' => ['workspace', 'config.instance_id', '10junk'],
    'workspace direct non-numeric' => ['workspace', 'config.instance_id', 'invalid'],
    'workspace nested decimal' => ['workspace', 'instance.id', 10.5],
    'workspace nested numeric string' => ['workspace', 'instance.id', '10'],
    'workspace nested zero' => ['workspace', 'instance.id', 0],
    'workspace nested negative' => ['workspace', 'instance.id', -10],
    'workspace nested whitespace' => ['workspace', 'instance.id', ' 10 '],
    'workspace nested junk' => ['workspace', 'instance.id', '10junk'],
    'workspace nested non-numeric' => ['workspace', 'instance.id', 'invalid'],
]);

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

it('fails when a configured instance and the route domain identify different instances', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'preview.docs.test');
        insertHistoricalProxyRoute(100, 'preview.docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 10],
        ]);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='preview.docs.test' owner_type='app' has competing instance identities: instance.id=>10, route.domain=>11",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails when a configured instance competes with an ambiguous matching route domain', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'shared.docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'shared.docs.test');
        insertHistoricalProxyRoute(100, 'shared.docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 10],
        ]);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='shared.docs.test' owner_type='app' has ambiguous domain ownership candidates [10, 11]",
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

it('fails before schema mutation when a workspace app conflicts with its selected instance app', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        DB::table('apps')->insert(['id' => 2, 'name' => 'store', 'runtime' => 'php']);
        insertProxyRouteOwnershipInstance(10, 'development', 'store.test');
        DB::table('instances')->where('id', 10)->update(['app_id' => 2]);
        insertProxyRouteOwnershipWorkspace(20, 10);
        insertHistoricalProxyRoute(100, 'feature.store.test', 'workspace', 'workspace', 2, 20);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "ProxyRoute instance ownership migration blocked by proxy_routes#100 domain='feature.store.test' owner_type='workspace' workspace_id=20 app_id=1 conflicts with instance_id=10 app_id=2. Repair or remove this legacy route, then rerun migrations.",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails before schema mutation when a workspace route has no app identity', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipWorkspace(20, 10);
        insertHistoricalProxyRoute(100, 'feature.docs.test', 'workspace', 'workspace', null, 20);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='feature.docs.test' owner_type='workspace' has no app_id",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('fails closed on rerun without overwriting conflicting persisted workspace ownership', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'preview.docs.test');
        insertProxyRouteOwnershipWorkspace(20, 10);
        insertHistoricalProxyRoute(100, 'feature.docs.test', 'workspace', 'workspace', 1, 20);

        proxyRouteInstanceOwnershipMigration()->up();
        DB::table('proxy_routes')->where('id', 100)->update(['instance_id' => 11]);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='feature.docs.test' owner_type='workspace' workspace owner instance_id=10 conflicts with persisted instance_id=11",
            )
            ->and(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBe(11);
    });
});

it('fails closed on rerun when an owner was deleted after a successful backfill', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1);

        proxyRouteInstanceOwnershipMigration()->up();
        insertProxyRouteOwnershipInstance(11, 'preview', 'preview.docs.test');
        DB::table('instances')->where('id', 10)->delete();

        expect(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBeNull()
            ->and(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='app' has no positive legacy evidence for an existing Instance owner",
            );
    });
});

it('recomputes valid legacy ownership when the schema exists but DML was interrupted', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 10],
        ]);
        addProxyRouteInstanceOwnershipColumn();

        expect(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBeNull();

        proxyRouteInstanceOwnershipMigration()->up();

        expect(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBe(10);
    });
});

it('rejects the public instance projection label as a persisted owner type', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertHistoricalProxyRoute(100, 'docs.test', 'instance', 'app', 1);

        expect(fn (): mixed => proxyRouteInstanceOwnershipMigration()->up())
            ->toThrow(
                RuntimeException::class,
                "proxy_routes#100 domain='docs.test' owner_type='instance' uses the public instance projection label as persisted ownership",
            )
            ->and(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse();
    });
});

it('preserves public binding instance ownership through an up down up cycle', function (string $ownerType): void {
    withHistoricalProxyRouteOwnershipSchema(function () use ($ownerType): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipInstance(11, 'preview', 'preview.docs.test');

        $migration = proxyRouteInstanceOwnershipMigration();
        $migration->up();

        DB::table('proxy_routes')->insert([
            'id' => 100,
            'node_id' => 1,
            'domain' => "{$ownerType}.docs.test",
            'app_id' => 1,
            'workspace_id' => null,
            'instance_id' => 11,
            'owner_type' => $ownerType,
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => json_encode([
                'protocol' => $ownerType === 'app-analytics' ? 'analytics' : 'websocket',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        $rolledBackConfig = json_decode(
            (string) DB::table('proxy_routes')->where('id', 100)->value('config'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse()
            ->and($rolledBackConfig['instance_id'] ?? null)
            ->toBe(11);

        $migration->up();

        expect(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBe(11)
            ->and(fn (): mixed => $migration->up())
            ->not->toThrow(Throwable::class);
    });
})->with([
    'analytics route' => 'app-analytics',
    'websocket route' => 'app-websocket',
]);

it('preserves workspace instance ownership through an up down up cycle', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        insertProxyRouteOwnershipWorkspace(20, 10);

        $migration = proxyRouteInstanceOwnershipMigration();
        $migration->up();

        DB::table('proxy_routes')->insert([
            'id' => 100,
            'node_id' => 1,
            'domain' => 'feature.docs.test',
            'app_id' => 1,
            'workspace_id' => 20,
            'instance_id' => 10,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'source_hash' => str_repeat('a', 64),
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        $rolledBackConfig = json_decode(
            (string) DB::table('proxy_routes')->where('id', 100)->value('config'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(Schema::hasColumn('proxy_routes', 'instance_id'))
            ->toBeFalse()
            ->and($rolledBackConfig['instance_id'] ?? null)
            ->toBe(10);

        $migration->up();

        expect(DB::table('proxy_routes')->where('id', 100)->value('instance_id'))
            ->toBe(10)
            ->and(fn (): mixed => $migration->up())
            ->not->toThrow(Throwable::class);
    });
});

it('rewrites legacy runtime targets and hashes on the migration connection idempotently', function (): void {
    withHistoricalProxyRouteOwnershipSchema(function (): void {
        insertProxyRouteOwnershipApp();
        insertProxyRouteOwnershipInstance(10, 'development', 'docs.test');
        $legacyUpstream = 'http://orbit-app-docs:8080';

        insertHistoricalProxyRoute(100, 'docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 10],
            'document_root' => '/srv/docs-development/public',
            'runtime_upstream' => $legacyUpstream,
            'tls' => [
                'cert_path' => '/etc/orbit/certs/docs.test.crt',
                'key_path' => '/etc/orbit/certs/docs.test.key',
            ],
        ]);
        insertHistoricalProxyRoute(101, 'public.docs.test', 'app', 'app', 1, null, [
            'instance' => ['id' => 10],
            'placement' => 'ingress',
            'document_root' => '/srv/docs-development/public',
            'runtime_upstream' => $legacyUpstream,
            'backend_artifacts' => [[
                'node_id' => 1,
                'bind' => '10.6.0.21',
                'document_root' => '/srv/docs-development/public',
                'runtime_upstream' => $legacyUpstream,
            ]],
        ]);

        proxyRouteInstanceOwnershipMigration()->up();

        $firstRows = DB::table('proxy_routes')->orderBy('id')->get()->keyBy('id');
        $directConfig = json_decode((string) $firstRows[100]->config, true, flags: JSON_THROW_ON_ERROR);
        $ingressConfig = json_decode((string) $firstRows[101]->config, true, flags: JSON_THROW_ON_ERROR);

        expect($directConfig['runtime_upstream'])
            ->toBe('http://orbit-app-docs-development:8080')
            ->and($firstRows[100]->source_hash)
            ->not
            ->toBe(str_repeat('a', 64))
            ->and($ingressConfig['runtime_upstream'])
            ->toBe('http://orbit-app-docs-development:8080')
            ->and($ingressConfig['backend_artifacts'][0]['runtime_upstream'])
            ->toBe('http://orbit-app-docs-development:8080')
            ->and($ingressConfig['backend_artifacts'][0]['source_hash'])
            ->toHaveLength(64);

        proxyRouteInstanceOwnershipMigration()->up();

        expect(
            DB::table('proxy_routes')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(
                    static fn (object $row): array => [(int) $row->id => [$row->source_hash, $row->config]],
                )
                ->all(),
        )
            ->toBe(
                $firstRows->mapWithKeys(
                    static fn (object $row): array => [(int) $row->id => [$row->source_hash, $row->config]],
                )->all(),
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
    Schema::create('node_role', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
        $table->string('role');
        $table->string('status');
        $table->json('settings')->nullable();
    });
    Schema::create('apps', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('runtime')->default('php');
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
    DB::table('node_role')->insert([
        'node_id' => 1,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => json_encode([], JSON_THROW_ON_ERROR),
    ]);

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
    DB::table('apps')->insert(['id' => 1, 'name' => 'docs', 'runtime' => 'php']);
}

function addProxyRouteInstanceOwnershipColumn(): void
{
    Schema::table('proxy_routes', static function (Blueprint $table): void {
        $table
            ->foreignId('instance_id')
            ->nullable()
            ->after('workspace_id')
            ->constrained('instances')
            ->nullOnDelete();
        $table->index(['instance_id', 'owner_type']);
    });
}

function insertProxyRouteOwnershipInstance(int $id, string $name, string $domain): void
{
    DB::table('instances')->insert([
        'id' => $id,
        'app_id' => 1,
        'name' => $name,
        'driver' => 'orbit',
        'driver_config' => json_encode([
            'type' => 'orbit_instance_driver_config',
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
