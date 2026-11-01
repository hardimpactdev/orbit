<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\PostgresTestDatabases;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Concerns\TestDatabases;
use Symfony\Component\Process\Exception\ProcessFailedException;

require_once __DIR__.'/../../../Support/PostgresServer.php';

it('reuses one psql client with fresh sessions and preserves explicit exit and SQL failures', function (): void {
    postgres_statements_server(function (Closure $psql): void {
        $psql("\\set fixture_client reused\nCREATE ROLE session_role; CREATE DATABASE session_database;");
        expect($psql("\\connect session_database\nSET ROLE session_role; SELECT current_database() || '|' || current_user;"))
            ->toBe('session_database|session_role');
        expect($psql("\\echo :fixture_client\nSELECT current_database() || '|' || current_user;"))
            ->toBe("reused\npostgres|orbit_test");
        $psql("BEGIN; CREATE TABLE uncommitted (id int);\n\\quit\n");
        expect($psql("SELECT to_regclass('uncommitted') IS NULL;"))->toBe('t');
        expect(fn () => $psql('SELECT 1/0;'))->toThrow(ProcessFailedException::class, 'division by zero');
        expect($psql('SELECT 42;'))->toBe('42');
    });
});

it('keeps the immutable PostgreSQL seed after a forked contender exits', function (): void {
    postgres_statements_server(fn (Closure $psql) => expect($psql('SELECT 1;'))->toBe('1'));
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork a PostgreSQL fixture contender.');
    }
    if ($pid === 0) {
        exit(0);
    }
    pcntl_waitpid($pid, $status);
    expect(pcntl_wexitstatus($status))->toBe(0);
    postgres_statements_server(fn (Closure $psql) => expect($psql('SELECT 2;'))->toBe('2'));
});

it('reuses precreated PostgreSQL workers through stock Laravel without CREATEDB and isolates their schemas', function (): void {
    postgres_statements_server(function (Closure $psql, string $directory): void {
        $pool = new PostgresTestDatabases;
        $names = ['parallel', ...$pool->names('parallel_test', 2)];
        $create = 'CREATE ROLE parallel_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;';
        foreach ($names as $name) {
            $create .= 'CREATE DATABASE "'.$name.'" OWNER parallel_owner TEMPLATE template0 ENCODING \'UTF8\' LOCALE_PROVIDER builtin BUILTIN_LOCALE \'C.UTF-8\';'
                .'REVOKE ALL ON DATABASE "'.$name.'" FROM PUBLIC;';
        }
        $psql($create);
        expect($psql("SELECT rolsuper::text || '|' || rolcreatedb::text || '|' || rolcreaterole::text FROM pg_roles WHERE rolname = 'parallel_owner';"))->toBe('false|false|false');
        $psql("\\connect parallel\nSET ROLE parallel_owner; CREATE TABLE application_data (id bigint); INSERT INTO application_data VALUES (42); RESET ROLE;\n");
        $psql('CREATE ROLE unrelated_owner LOGIN; CREATE DATABASE unrelated OWNER unrelated_owner; REVOKE ALL ON DATABASE unrelated FROM PUBLIC;');
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
        $psql(implode('', array_map(static fn (string $name): string => 'DROP DATABASE IF EXISTS "'.$name.'";', $names)).'DROP ROLE parallel_owner;');
    });
});
