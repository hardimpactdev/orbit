<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Concerns\TestDatabases;

require_once __DIR__.'/../../../Support/PostgresServer.php';

it('applies PostgreSQL statements with builtin locale, owner and current and future reader grants on PG18', function (): void {
    postgres_statements_server(function (Closure $psql): void {
        $sql = new PostgresStatements;
        $psql($sql->ensureUser('catalog_owner', 'fixture-password').$sql->createDatabase('catalog', 'C.UTF-8').$sql->grantAll('catalog', 'catalog_owner'));
        expect($psql("SELECT datlocprovider::text || '|' || datlocale || '|' || pg_encoding_to_char(encoding) FROM pg_database WHERE datname = 'catalog';"))->toBe('b|C.UTF-8|UTF8');
        $psql("\\connect catalog\nSET ROLE catalog_owner; CREATE TABLE current_table (id bigint GENERATED ALWAYS AS IDENTITY, name text); INSERT INTO current_table (name) VALUES ('fixture'); RESET ROLE;\n");
        $psql($sql->ensureUser('catalog_reader', 'reader-password').$sql->replaceGrant('catalog', 'catalog_reader', false, 'catalog_owner'));
        $psql($sql->replaceGrant('catalog', 'catalog_reader', true, 'catalog_owner'));
        $psql("\\connect catalog\nSET ROLE catalog_owner; CREATE TABLE future_table (id bigint); RESET ROLE;\n");
        expect($psql("\\connect catalog\nSELECT has_table_privilege('catalog_reader','current_table','SELECT')::text || '|' || has_table_privilege('catalog_reader','current_table','INSERT')::text || '|' || has_table_privilege('catalog_reader','future_table','SELECT')::text || '|' || has_table_privilege('catalog_reader','future_table','INSERT')::text;"))->toBe('true|false|true|false');
        expect($psql("\\connect catalog\nSET ROLE catalog_reader; SELECT name FROM current_table;"))->toBe('fixture');
        expect($psql('SHOW wal_level;'))->toBe('logical');
        $psql($sql->ensureUser('catalog_sync', 'sync-password').$sql->replaceGrant('catalog', 'catalog_sync', false, 'catalog_owner'));
        $psql($sql->ensureUser('catalog_sync', 'rotated-sync-password', replication: true).$sql->replaceGrant('catalog', 'catalog_sync', true, 'catalog_owner'));
        $psql("\\connect catalog\nSET ROLE catalog_owner; CREATE TABLE later_table (id bigint); CREATE PUBLICATION app_sync FOR TABLE current_table, later_table; RESET ROLE;\n");
        expect($psql("SELECT rolcanlogin::text || '|' || rolreplication::text || '|' || rolsuper::text || '|' || rolcreatedb::text || '|' || rolcreaterole::text FROM pg_roles WHERE rolname = 'catalog_sync';"))->toBe('true|true|false|false|false');
        expect($psql("\\connect catalog\nSELECT has_database_privilege('catalog_sync','catalog','CONNECT')::text || '|' || has_schema_privilege('catalog_sync','public','USAGE')::text || '|' || has_table_privilege('catalog_sync','current_table','SELECT')::text || '|' || has_table_privilege('catalog_sync','later_table','SELECT')::text || '|' || has_table_privilege('catalog_sync','later_table','INSERT')::text;"))->toBe('true|true|true|true|false');
        expect($psql("\\connect catalog\nSET ROLE catalog_sync; SELECT name FROM current_table;"))->toBe('fixture');
        expect($psql("\\connect catalog\nSET ROLE catalog_sync; SELECT slot_name FROM pg_create_logical_replication_slot('orbit_sync_test', 'pgoutput');"))->toBe('orbit_sync_test');
        $psql("\\connect catalog\nSET ROLE catalog_sync; SELECT pg_drop_replication_slot('orbit_sync_test');");
        $psql($sql->ensureUser('catalog_sync', 'final-sync-password'));
        expect($psql("SELECT rolreplication::text FROM pg_roles WHERE rolname = 'catalog_sync';"))->toBe('false');
        $psql($sql->dropDatabase('catalog').$sql->dropUser('catalog_reader').$sql->dropUser('catalog_owner').$sql->dropUser('catalog_sync'));
    });
});

it('reuses precreated PostgreSQL workers through stock Laravel without CREATEDB and isolates their schemas', function (): void {
    postgres_statements_server(function (Closure $psql, string $directory): void {
        $sql = new PostgresStatements;
        $pool = new PostgresTestDatabases;
        $names = ['parallel', ...$pool->names('parallel_test', 2)];
        $create = $sql->ensureUser('parallel_owner', 'fixture-password');
        foreach ($names as $name) {
            $create .= $sql->createDatabase($name, 'C.UTF-8').$sql->grantAll($name, 'parallel_owner');
        }
        $psql($create);
        expect($psql("SELECT rolsuper::text || '|' || rolcreatedb::text || '|' || rolcreaterole::text FROM pg_roles WHERE rolname = 'parallel_owner';"))->toBe('false|false|false');
        $psql("\\connect parallel\nSET ROLE parallel_owner; CREATE TABLE application_data (id bigint); INSERT INTO application_data VALUES (42); RESET ROLE;\n");
        $psql($sql->ensureUser('unrelated_owner', 'unrelated-password').$sql->createDatabase('unrelated').$sql->grantAll('unrelated', 'unrelated_owner'));
        expect($psql("SELECT has_database_privilege('parallel_owner', 'unrelated', 'CONNECT')::text;"))->toBe('false');

        $default = config('database.default');
        config()->set('database.connections.pgsql_pool', ['driver' => 'pgsql', 'host' => $directory, 'port' => 5432, 'database' => 'parallel_test', 'username' => 'parallel_owner', 'password' => '', 'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'disable']);
        config()->set('database.default', 'pgsql_pool');
        $probe = new class
        {
            use TestDatabases {
                ensureTestDatabaseExists as public ensure;
                switchToDatabase as public switchDatabase;
            }
        };
        try {
            foreach ([1, 2] as $token) {
                ParallelTesting::resolveTokenUsing(fn (): int => $token);
                expect($probe->ensure('parallel_test'))->toBe(['parallel_test_test_'.$token, false]);
                $probe->switchDatabase('parallel_test_test_'.$token);
                Schema::create('worker_data', function (Blueprint $table): void {
                    $table->integer('token');
                });
                DB::table('worker_data')->insert(['token' => $token]);
            }
            $probe->switchDatabase('parallel_test_test_1');
            expect(DB::table('worker_data')->pluck('token')->all())->toBe([1]);
            Schema::drop('worker_data');
            $probe->switchDatabase('parallel_test_test_2');
            expect(DB::table('worker_data')->pluck('token')->all())->toBe([2]);
            Schema::drop('worker_data');
            expect(fn () => DB::statement('CREATE DATABASE unmanaged_worker'))->toThrow(QueryException::class, 'permission denied to create database');
            $psql('ALTER DATABASE parallel_test_test_1 CONNECTION LIMIT 0;');
            ParallelTesting::resolveTokenUsing(fn (): int => 1);
            expect(fn () => $probe->ensure('parallel_test'))->toThrow(QueryException::class, 'permission denied to create database');
            expect($psql("SELECT datname FROM pg_database WHERE datname IN ('parallel', 'parallel_test', 'parallel_test_test_1', 'parallel_test_test_2') ORDER BY datname;"))->toBe("parallel\nparallel_test\nparallel_test_test_2");
        } finally {
            DB::purge('pgsql_pool');
            config()->set('database.default', $default);
            ParallelTesting::resolveTokenUsing(null);
        }
        expect($psql("\\connect parallel\nSELECT id FROM application_data;"))->toBe('42');
        $psql(implode('', array_map($sql->dropDatabase(...), $names)).$sql->dropUser('parallel_owner'));
    });
});
