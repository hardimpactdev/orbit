<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @param array<string, mixed> $arguments */
function run_database_operation_contract(string $fixture, string $command, array $arguments, string $expected, int $exitCode): void
{
    $terminal = gateway_fixture($fixture);
    $request = match ($command) {
        'database:create' => CreateDatabaseConnectionRequest::class,
        'database:user:create' => CreateDatabaseUserRequest::class,
        'database:server:create' => CreateDatabaseServerRequest::class,
        'database:server:close' => CloseDatabaseServerRequest::class,
    };
    MockClient::destroyGlobal();
    MockClient::global([
        ...gateway_fixture_mock($fixture),
        $request => MockResponse::make($terminal['body'], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
    ]);

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}
