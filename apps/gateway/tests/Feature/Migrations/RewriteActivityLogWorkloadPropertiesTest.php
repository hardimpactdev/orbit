<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('rewrites stored activity_log project and app_instance property keys to app and instance', function (): void {
    with_activity_log_vocabulary_schema(function (): void {
        $now = '2026-08-05 12:00:00';

        DB::table('activity_log')->insert([
            [
                'id' => 1,
                'log_name' => 'api',
                'description' => 'showed app',
                'event' => 'app.shown',
                'properties' => json_encode([
                    'type' => 'read',
                    'command' => 'app:show',
                    'project' => 'docs',
                    'project_name' => 'docs',
                    'app_instance' => 'development',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'log_name' => 'api',
                'description' => 'already canonical',
                'event' => 'app.listed',
                'properties' => json_encode([
                    'type' => 'read',
                    'app' => 'billing',
                    'instance' => 'production',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'log_name' => 'security',
                'description' => 'host key changed',
                'event' => 'node.host_key.changed',
                'properties' => json_encode([
                    'type' => 'ssh-ed25519',
                    'fingerprint' => 'SHA256:keep-me',
                    'project' => 'should-become-app',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'log_name' => 'api',
                'description' => 'dual keys prefer app',
                'event' => 'app.shown',
                'properties' => json_encode([
                    'type' => 'read',
                    'project' => 'old',
                    'app' => 'canonical',
                    'app_instance' => 'old-instance',
                    'instance' => 'canonical-instance',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        run_activity_log_vocabulary_cutover_migration();

        $row1 = json_decode(
            (string) DB::table('activity_log')->where('id', 1)->value('properties'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $row2 = json_decode(
            (string) DB::table('activity_log')->where('id', 2)->value('properties'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $row3 = json_decode(
            (string) DB::table('activity_log')->where('id', 3)->value('properties'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $row4 = json_decode(
            (string) DB::table('activity_log')->where('id', 4)->value('properties'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($row1)
            ->toMatchArray([
                'type' => 'read',
                'command' => 'app:show',
                'app' => 'docs',
                'app_name' => 'docs',
                'instance' => 'development',
            ])
            ->not->toHaveKey('project')
            ->not->toHaveKey('project_name')
            ->not->toHaveKey('app_instance')->and($row2)->toMatchArray([
                'type' => 'read',
                'app' => 'billing',
                'instance' => 'production',
            ])->and($row3)->toMatchArray([
                'type' => 'ssh-ed25519',
                'fingerprint' => 'SHA256:keep-me',
                'app' => 'should-become-app',
            ])
            ->not->toHaveKey('project')->and($row4)->toMatchArray([
                'type' => 'read',
                'app' => 'canonical',
                'instance' => 'canonical-instance',
            ])
            ->not->toHaveKey('project')
            ->not->toHaveKey('app_instance');

        // Immutable historical command text in properties is not rewritten.
        expect($row1['command'])->toBe('app:show');
    });
});

it('rewrites large activity_log sets with bounded chunkById selects rather than one unbounded get()', function (): void {
    with_activity_log_vocabulary_schema(function (): void {
        $now = '2026-08-05 12:00:00';
        $rowCount = 1250;
        $rows = [];

        for ($id = 1; $id <= $rowCount; $id++) {
            $rows[] = [
                'id' => $id,
                'log_name' => 'api',
                'description' => "row-{$id}",
                'event' => 'app.shown',
                'properties' => json_encode([
                    'type' => 'read',
                    'project' => "app-{$id}",
                    'project_name' => "name-{$id}",
                    'app_instance' => ($id % 2) === 0 ? 'development' : 'production',
                    'payload' => str_repeat('x', times: 64),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('activity_log')->insert($chunk);
        }

        /** @var list<string> $activitySelects */
        $activitySelects = [];

        DB::listen(static function (object $query) use (&$activitySelects): void {
            $sql = (string) $query->sql;

            if (
                preg_match('/\bfrom\s+["`]?activity_log["`]?/i', $sql) === 1
                && preg_match('/^\s*select\b/i', $sql) === 1
            ) {
                $activitySelects[] = $sql;
            }
        });

        run_activity_log_vocabulary_cutover_migration();

        expect($activitySelects)->not->toBeEmpty();

        $boundedSelects = array_values(array_filter(
            $activitySelects,
            static fn (string $sql): bool => preg_match('/\blimit\b/i', $sql) === 1,
        ));

        expect($boundedSelects)
            ->not
            ->toBeEmpty('activity_log rewriting must issue LIMIT-bounded selects')
            ->and(count($boundedSelects))
            ->toBeGreaterThan(1, 'large activity_log rewrites must use more than one bounded page');

        foreach ($activitySelects as $sql) {
            // No unbounded full-table materialization of activity_log properties.
            if (preg_match('/\bproperties\b/i', $sql) !== 1) {
                continue;
            }

            expect($sql)->toMatch('/\blimit\b/i');
        }

        $rewritten = DB::table('activity_log')->orderBy('id')->get(['id', 'properties']);

        expect($rewritten)->toHaveCount($rowCount);

        foreach ($rewritten as $row) {
            $properties = json_decode((string) $row->properties, true, flags: JSON_THROW_ON_ERROR);

            expect($properties)
                ->toMatchArray([
                    'type' => 'read',
                    'app' => "app-{$row->id}",
                    'app_name' => "name-{$row->id}",
                    'instance' => ((int) $row->id % 2) === 0 ? 'development' : 'production',
                ])
                ->not->toHaveKey('project')
                ->not->toHaveKey('project_name')
                ->not->toHaveKey('app_instance');
        }
    });
});

it('is safe to re-run activity_log workload rewriting over mixed pre-cutover and already-canonical rows', function (): void {
    with_activity_log_vocabulary_schema(function (): void {
        $now = '2026-08-05 12:00:00';

        DB::table('activity_log')->insert([
            [
                'id' => 1,
                'log_name' => 'api',
                'description' => 'legacy',
                'event' => 'app.shown',
                'properties' => json_encode([
                    'project' => 'docs',
                    'project_name' => 'Docs',
                    'app_instance' => 'development',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'log_name' => 'api',
                'description' => 'already rewritten',
                'event' => 'app.listed',
                'properties' => json_encode([
                    'app' => 'billing',
                    'app_name' => 'Billing',
                    'instance' => 'production',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'log_name' => 'api',
                'description' => 'partial dual keys',
                'event' => 'app.shown',
                'properties' => json_encode([
                    'project' => 'stale',
                    'app' => 'canonical',
                    'app_instance' => 'stale-instance',
                    'instance' => 'canonical-instance',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        run_activity_log_vocabulary_cutover_migration();
        run_activity_log_vocabulary_cutover_migration();

        $byId = DB::table('activity_log')->orderBy('id')->pluck('properties', 'id');

        expect(json_decode((string) $byId[1], true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray([
                'app' => 'docs',
                'app_name' => 'Docs',
                'instance' => 'development',
            ])
            ->not->toHaveKey('project')
            ->not->toHaveKey('app_instance')->and(json_decode(
                (string) $byId[2],
                true,
                flags: JSON_THROW_ON_ERROR,
            ))->toMatchArray([
                'app' => 'billing',
                'app_name' => 'Billing',
                'instance' => 'production',
            ])->and(json_decode((string) $byId[3], true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
                'app' => 'canonical',
                'instance' => 'canonical-instance',
            ])
            ->not->toHaveKey('project')
            ->not->toHaveKey('app_instance');
    });
});

it('aborts the atomic cutover when activity_log properties are malformed, without partial commit', function (): void {
    with_activity_log_vocabulary_schema(function (): void {
        $now = '2026-08-05 12:00:00';

        // Pre-cutover instance table so schema rename is in scope of the same transaction.
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('app_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('name');
            $table->json('driver_config')->nullable();
            $table->timestamps();
        });

        DB::table('apps')->insert([
            'id' => 1,
            'name' => 'docs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_instances')->insert([
            'id' => 10,
            'app_id' => 1,
            'name' => 'development',
            'driver_config' => json_encode([
                'type' => 'orbit_app_instance_driver_config',
                'data' => [],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('activity_log')->insert([
            [
                'id' => 1,
                'log_name' => 'api',
                'description' => 'valid row before malformed',
                'event' => 'app.shown',
                'properties' => json_encode([
                    'type' => 'read',
                    'project' => 'docs',
                    'app_instance' => 'development',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'log_name' => 'api',
                'description' => 'malformed properties',
                'event' => 'app.shown',
                'properties' => '{not-json',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('node_access')->insert([
            'id' => 1,
            'permissions' => json_encode(['app:read'], JSON_THROW_ON_ERROR),
            'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn () => run_activity_log_vocabulary_cutover_migration())
            ->toThrow(JsonException::class);

        // Transaction aborted: no partial schema/data cutover.
        expect(Schema::hasTable('app_instances'))
            ->toBeTrue()
            ->and(Schema::hasTable('instances'))
            ->toBeFalse()
            ->and(DB::table('app_instances')->count())
            ->toBe(1)
            ->and((string) DB::table('activity_log')->where('id', 1)->value('properties'))
            ->toBe(json_encode([
                'type' => 'read',
                'project' => 'docs',
                'app_instance' => 'development',
            ], JSON_THROW_ON_ERROR))
            ->and((string) DB::table('activity_log')->where('id', 2)->value('properties'))
            ->toBe('{not-json')
            ->and(json_decode((string) DB::table('node_access')->where('id', 1)->value('permissions'), true))
            ->toBe(['app:read']);
    });
});

/**
 * @param  Closure(): void  $callback
 */
function with_activity_log_vocabulary_schema(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    $connection = 'activity_log_vocabulary_cutover';

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
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        // Minimal tables the cutover migration may probe.
        Schema::create('node_access', function (Blueprint $table): void {
            $table->id();
            $table->json('permissions');
            $table->json('custom_permissions');
            $table->timestamps();
        });

        $callback();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function run_activity_log_vocabulary_cutover_migration(): void
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
