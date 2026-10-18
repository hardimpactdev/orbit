<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliCollectorEnvironment;
use App\Domain\ProxyCli\ProxyCliState;
use App\Models\DatabaseConnection;

it('refuses missing cache placement instead of passing an empty value to the collector', function (string $field, string $message): void {
    app(ProxyCliState::class)->enable(8, 'valkey', 'http://127.0.0.1:8317', 'management', 'read', 'control');
    $connection = new DatabaseConnection(['driver' => 'redis', 'host' => '10.44.0.8', 'port' => 6379, $field => null]);

    expect(fn () => app(ProxyCliCollectorEnvironment::class)->build($connection))
        ->toThrow(LogicException::class, $message);
})->with([
    'missing host' => ['host', 'ProxyCli cache connection has no host.'],
    'missing port' => ['port', 'ProxyCli cache connection has no port.'],
]);
