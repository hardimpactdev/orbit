<?php

declare(strict_types=1);

use Orbit\Core\Database\DatabaseReadOnlyGuard;

describe(DatabaseReadOnlyGuard::class, function (): void {
    it('restores the prior sqlite query-only state after user sql changes it', function (): void {
        $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('create table users (id integer primary key, name text not null)');
        $database->exec("insert into users (id, name) values (1, 'Ada')");

        new DatabaseReadOnlyGuard()->run(
            $database,
            'sqlite',
            function () use ($database): void {
                expect($database->query('pragma query_only')->fetchColumn())->toBe(1);

                $statement = $database->prepare(
                    'pragma query_only = off; update users set name = "Changed" where id = 1',
                );
                $statement->execute();
            },
        );

        expect($database->query('pragma query_only')->fetchColumn())
            ->toBe(0)
            ->and($database->query('select name from users where id = 1')->fetchColumn())
            ->toBe('Ada');
    });

    it('restores sqlite query-only after the callback fails', function (): void {
        $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        expect(fn (): mixed => new DatabaseReadOnlyGuard()->run(
            $database,
            'sqlite',
            function () use ($database): never {
                $database->exec('pragma query_only = off');

                throw new RuntimeException('query failed');
            },
        ))
            ->toThrow(RuntimeException::class, 'query failed')
            ->and($database->query('pragma query_only')->fetchColumn())
            ->toBe(0);
    });

    it('preserves an enabled sqlite query-only state', function (): void {
        $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('pragma query_only = on');

        new DatabaseReadOnlyGuard()->run($database, 'sqlite', static fn (): null => null);

        expect($database->query('pragma query_only')->fetchColumn())->toBe(1);
    });

    it('rolls back mysql read-only transactions after a rejected write', function (): void {
        assert_database_read_only_transaction_cleanup('mysql');
    });

    it('rolls back postgresql read-only transactions after a rejected write', function (): void {
        assert_database_read_only_transaction_cleanup('pgsql');
    });
});

function assert_database_read_only_transaction_cleanup(string $driver): void
{
    $configuration = database_read_only_guard_test_configuration($driver);

    if ($configuration === null) {
        $variable = $driver === 'mysql'
            ? 'ORBIT_DATABASE_QUERY_MYSQL_PORT'
            : 'ORBIT_DATABASE_QUERY_PGSQL_PORT';

        test()->markTestSkipped("Set {$variable} to run the {$driver} driver test.");
    }

    $database = database_read_only_guard_test_pdo($configuration);
    $table = 'orbit_query_cleanup_'.bin2hex(random_bytes(6));
    $database->exec("create table {$table} (id integer primary key, name varchar(255) not null)");
    $database->exec("insert into {$table} (id, name) values (1, 'Ada')");

    try {
        expect(fn (): mixed => new DatabaseReadOnlyGuard()->run(
            $database,
            $driver,
            function () use ($database, $table): void {
                expect($database->inTransaction())->toBeTrue();

                $database->exec("update {$table} set name = 'Rejected' where id = 1");
            },
        ))
            ->toThrow(PDOException::class)
            ->and($database->inTransaction())
            ->toBeFalse();

        $database->exec("update {$table} set name = 'Allowed' where id = 1");

        expect($database->query("select name from {$table} where id = 1")->fetchColumn())
            ->toBe('Allowed');
    } finally {
        $database->exec("drop table if exists {$table}");
    }
}

/**
 * @return null|array{driver: 'mysql'|'pgsql', host: string, port: int, database: string, username: string, password: string}
 */
function database_read_only_guard_test_configuration(string $driver): ?array
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
        'host' => database_read_only_guard_test_environment(name: "{$prefix}_HOST", default: '127.0.0.1'),
        'port' => (int) $port,
        'database' => database_read_only_guard_test_environment(
            name: "{$prefix}_DATABASE",
            default: 'orbit_read_only',
        ),
        'username' => database_read_only_guard_test_environment(
            name: "{$prefix}_USERNAME",
            default: $driver === 'mysql' ? 'root' : 'postgres',
        ),
        'password' => database_read_only_guard_test_environment(
            name: "{$prefix}_PASSWORD",
            default: 'orbit-test',
        ),
    ];
}

function database_read_only_guard_test_environment(string $name, string $default): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * @param  array{driver: 'mysql'|'pgsql', host: string, port: int, database: string, username: string, password: string}  $configuration
 */
function database_read_only_guard_test_pdo(array $configuration): PDO
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
