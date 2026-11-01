<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseUserPrivileges;
use App\Domain\Shared\ResourceOperationException;

it('records driver-specific public privilege text without compiling SQL', function (): void {
    $text = new DatabaseUserPrivileges;
    expect($text->text(DatabaseDriver::Mysql, 'app', prefix: 'app_test'))->toBe('ALL PRIVILEGES ON `app`.*, `app\\_test%`.*')
        ->and($text->text(DatabaseDriver::Mysql, 'app', true))->toBe('SELECT ON `app`.*')
        ->and($text->text(DatabaseDriver::Pgsql, 'app'))->toBe('DATA WRITE ON "app"')
        ->and($text->text(DatabaseDriver::Pgsql, 'app', true))->toBe('CONNECT, USAGE, SELECT ON "app"');
});

it('refuses unsafe database names before storing privilege metadata', function (string $name): void {
    expect(fn () => new DatabaseUserPrivileges()->text(DatabaseDriver::Mysql, $name))->toThrow(ResourceOperationException::class);
})->with(['app`; DROP DATABASE x; --', str_repeat('a', 65), 'app%']);
