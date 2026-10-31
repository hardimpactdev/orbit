<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Infrastructure\DatabaseConnections\DatabaseConnectionInput;
use App\Models\DatabaseConnection;

it('builds driver DSNs without embedding the password', function (DatabaseDriver $driver, string $dsn): void {
    $connection = new DatabaseConnection([
        'slug' => 'app',
        'driver' => $driver,
        'host' => 'db.example.test',
        'port' => $driver->defaultPort(),
        'database' => 'app',
        'path' => '/var/lib/app/database.sqlite',
        'username' => 'app',
    ]);
    $inspector = new DatabaseConnectionInput;

    expect($inspector->dsn($connection))
        ->toBe($dsn)
        ->and($inspector->dsn($connection))
        ->not->toContain('password');
})->with([
    'mysql' => [DatabaseDriver::Mysql, 'mysql:host=db.example.test;port=3306;dbname=app;charset=utf8mb4'],
    'pgsql' => [DatabaseDriver::Pgsql, 'pgsql:host=db.example.test;port=5432;dbname=app'],
    'sqlite' => [DatabaseDriver::Sqlite, 'sqlite:/var/lib/app/database.sqlite'],
]);
