<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Concerns\TestDatabases;
use Symfony\Component\Process\Process;

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
        $psql($sql->ensureUser('parallel_owner', 'fixture-password'));
        foreach ($names as $name) {
            $psql($sql->createDatabase($name, 'C.UTF-8').$sql->grantAll($name, 'parallel_owner'));
        }
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
        } finally {
            DB::purge('pgsql_pool');
            config()->set('database.default', $default);
            ParallelTesting::resolveTokenUsing(null);
        }
        expect($psql("\\connect parallel\nSELECT id FROM application_data;"))->toBe('42');
        foreach ($names as $name) {
            $psql($sql->dropDatabase($name));
        }
        $psql($sql->dropUser('parallel_owner'));
    });
});

/** @param Closure(Closure(string): string, string): void $test */
function postgres_statements_server(Closure $test): void
{
    $bin = rtrim(getenv('ORBIT_POSTGRES18_BIN') ?: '/usr/lib/postgresql/18/bin', '/').'/';
    if (! is_executable($bin.'initdb')) {
        if (getenv('CI')) {
            test()->fail('The CI runner requires PostgreSQL 18 binaries.');
        }
        test()->markTestSkipped('PostgreSQL 18 binaries are unavailable.');
    }
    $directory = sys_get_temp_dir().'/orbit-pg-statements-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $run = static function (array $arguments, ?string $input = null) use ($directory): string {
        $process = new Process($arguments, env: ['PGHOST' => $directory, 'PGPORT' => '5432', 'PGUSER' => 'orbit_test', 'PGDATABASE' => 'postgres']);
        $process->setInput($input);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $psql = static fn (string $sql): string => $run([$bin.'psql', '-X', '-A', '-t', '-q', '-v', 'ON_ERROR_STOP=1'], $sql);
    $started = false;
    try {
        $run([$bin.'initdb', '-D', $directory.'/data', '-U', 'orbit_test', '--auth=trust', '--no-locale', '--encoding=UTF8', '--no-sync']);
        $run([$bin.'pg_ctl', '-D', $directory.'/data', '-l', $directory.'/log', '-o', '-F -k '.$directory." -h '' -c wal_level=logical", '-w', 'start']);
        $started = true;
        $test($psql, $directory);
    } finally {
        if ($started) {
            $run([$bin.'pg_ctl', '-D', $directory.'/data', '-m', 'immediate', '-w', 'stop']);
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}
