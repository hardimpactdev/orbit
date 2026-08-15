<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseQueryRunner;
use App\Services\DatabaseConnections\DatabaseQueryRunnerFailure;

describe('database query runner read-only enforcement', function (): void {
    it('preserves sqlite reads and rejects writable pragmas', function (): void {
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-query-runner-sqlite-');
        $database = new PDO("sqlite:{$path}");
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('create table users (id integer primary key, name text not null)');
        $database->exec("insert into users (id, name) values (1, 'Ada')");

        try {
            $runner = app(DatabaseQueryRunner::class);
            $payload = [
                'driver' => 'sqlite',
                'path' => $path,
            ];

            expect($runner->run($payload, 'select name from users where id = 1')['data']['rows'])
                ->toBe([['name' => 'Ada']]);

            $failure = capture_database_query_runner_failure(
                fn (): array => $runner->run($payload, 'pragma user_version = 741'),
            );

            expect($failure?->errorCode)
                ->toBe('database_query.execution_failed')
                ->and($failure?->meta)
                ->toBe(['mode' => 'read'])
                ->and($database->query('pragma user_version')->fetchColumn())
                ->toBe(0);
        } finally {
            unset($database);
            unlink($path);
        }
    });

    it('preserves mysql reads and rejects side-effecting functions', function (): void {
        $configuration = database_query_runner_test_configuration('mysql');

        if ($configuration === null) {
            $this->markTestSkipped('Set ORBIT_DATABASE_QUERY_MYSQL_PORT to run the MySQL driver test.');
        }

        $database = database_query_runner_test_pdo($configuration);
        $suffix = bin2hex(random_bytes(6));
        $table = "orbit_query_guard_{$suffix}";
        $function = "orbit_query_mutate_{$suffix}";
        $database->exec("create table {$table} (id integer primary key, name varchar(255) not null) engine=InnoDB");
        $database->exec("insert into {$table} (id, name) values (1, 'Ada')");
        $database->exec(
            "create function {$function}() returns varchar(255) deterministic modifies sql data "
            ."begin update {$table} set name = 'Changed' where id = 1; return 'Changed'; end",
        );

        try {
            $runner = app(DatabaseQueryRunner::class);
            $payload = $configuration;

            expect($runner->run($payload, "select name from {$table} where id = 1")['data']['rows'])
                ->toBe([['name' => 'Ada']]);

            $failure = capture_database_query_runner_failure(
                fn (): array => $runner->run($payload, "select {$function}() as name"),
            );

            expect($failure?->errorCode)
                ->toBe('database_query.execution_failed')
                ->and($failure?->meta)
                ->toBe(['mode' => 'read'])
                ->and($database->query("select name from {$table} where id = 1")->fetchColumn())
                ->toBe('Ada');
        } finally {
            $database->exec("drop function if exists {$function}");
            $database->exec("drop table if exists {$table}");
        }
    });

    it('preserves postgresql reads and rejects explain analyze writes', function (): void {
        $configuration = database_query_runner_test_configuration('pgsql');

        if ($configuration === null) {
            $this->markTestSkipped('Set ORBIT_DATABASE_QUERY_PGSQL_PORT to run the PostgreSQL driver test.');
        }

        $database = database_query_runner_test_pdo($configuration);
        $table = 'orbit_query_guard_'.bin2hex(random_bytes(6));
        $database->exec("create table {$table} (id integer primary key, name varchar(255) not null)");
        $database->exec("insert into {$table} (id, name) values (1, 'Ada')");

        try {
            $runner = app(DatabaseQueryRunner::class);
            $payload = $configuration;

            expect($runner->run($payload, "select name from {$table} where id = 1")['data']['rows'])
                ->toBe([['name' => 'Ada']]);

            $failure = capture_database_query_runner_failure(
                fn (): array => $runner->run(
                    $payload,
                    "explain analyze update {$table} set name = 'Changed' where id = 1",
                ),
            );

            expect($failure?->errorCode)
                ->toBe('database_query.execution_failed')
                ->and($failure?->meta)
                ->toBe(['mode' => 'read'])
                ->and($database->query("select name from {$table} where id = 1")->fetchColumn())
                ->toBe('Ada');
        } finally {
            $database->exec("drop table if exists {$table}");
        }
    });
});

/**
 * @param  Closure(): array<mixed>  $callback
 */
function capture_database_query_runner_failure(Closure $callback): ?DatabaseQueryRunnerFailure
{
    try {
        $callback();
    } catch (DatabaseQueryRunnerFailure $failure) {
        return $failure;
    }

    return null;
}

/**
 * @return null|array{driver: 'mysql'|'pgsql', host: string, port: int, database: string, username: string, password: string}
 */
function database_query_runner_test_configuration(string $driver): ?array
{
    $prefix = $driver === 'mysql'
        ? 'ORBIT_DATABASE_QUERY_MYSQL'
        : 'ORBIT_DATABASE_QUERY_PGSQL';
    $port = getenv("{$prefix}_PORT");

    if (! is_string($port) || ! is_numeric($port)) {
        return null;
    }

    return [
        'driver' => $driver,
        'host' => database_query_runner_test_environment(name: "{$prefix}_HOST", default: '127.0.0.1'),
        'port' => (int) $port,
        'database' => database_query_runner_test_environment(
            name: "{$prefix}_DATABASE",
            default: 'orbit_read_only',
        ),
        'username' => database_query_runner_test_environment(
            name: "{$prefix}_USERNAME",
            default: $driver === 'mysql' ? 'root' : 'postgres',
        ),
        'password' => database_query_runner_test_environment(
            name: "{$prefix}_PASSWORD",
            default: 'orbit-test',
        ),
    ];
}

function database_query_runner_test_environment(string $name, string $default): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * @param  array{driver: 'mysql'|'pgsql', host: string, port: int, database: string, username: string, password: string}  $configuration
 */
function database_query_runner_test_pdo(array $configuration): PDO
{
    $dsn = match ($configuration['driver']) {
        'mysql' => sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $configuration['host'],
            $configuration['port'],
            $configuration['database'],
        ),
        'pgsql' => sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $configuration['host'],
            $configuration['port'],
            $configuration['database'],
        ),
    };

    return new PDO(
        $dsn,
        $configuration['username'],
        $configuration['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}
