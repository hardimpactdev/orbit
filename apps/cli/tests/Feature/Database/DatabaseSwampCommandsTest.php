<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\StandardInputReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DescribeDatabaseTableRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseSchemaRequest;
use Orbit\Sdk\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeStandardInput;

it('follows database Operations and forwards the start key without reading a deleted target', function (string $name, array $arguments, string $requestClass): void {
    $home = sys_get_temp_dir().'/database-swamp-cli-'.bin2hex(random_bytes(8));
    config()->set('orbit.home', $home);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.example.test', '/tmp/unused-ca.pem'));
    $data = ['id' => 7, 'kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => 2,
        'status' => 'succeeded', 'sequence' => 3, 'input' => ['intent_id' => 9], 'result' => ['backend' => 'active']];
    $envelope = ['data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']];
    $mock = MockClient::global([$requestClass => MockResponse::make($envelope, 202), ShowOperationRequest::class => MockResponse::make($envelope), ShowRealtimeRequest::class => MockResponse::make([], 503)]);
    app()->instance(StandardInputReader::class, FakeStandardInput::piped('synthetic-password'));
    try {
        $command = app(Kernel::class)->all()[$name];
        $tester = new CommandTester($command);

        $status = $tester->execute([...$arguments, '--json' => true, '--idempotency-key' => 'same-start-key'], ['interactive' => false]);

        expect($status)->toBe(0);
        expect(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['id' => 7, 'status' => 'succeeded']);
        $mock->assertSent(fn ($request): bool => $request instanceof $requestClass && $request->headers()->get('Idempotency-Key') === 'same-start-key');
    } finally {
        MockClient::destroyGlobal();
        (new Filesystem)->deleteDirectory($home);
    }
})->with([
    'connection-update' => ['database:update', ['slug' => 'throwaway', '--host' => '192.0.2.80'], UpdateDatabaseConnectionRequest::class],
    'server-create' => ['database:server:create', ['slug' => 'dev-db', '--node' => '7'], CreateDatabaseServerRequest::class],
    'server-close' => ['database:server:close', ['slug' => 'dev-db'], CloseDatabaseServerRequest::class],
    'server-destroy' => ['database:server:destroy', ['slug' => 'dev-db', '--force' => true], DestroyDatabaseServerRequest::class],
    'create' => ['database:create', ['slug' => 'throwaway', '--server' => 'dev-db'], CreateDatabaseConnectionRequest::class],
    'user-create' => ['database:user:create', ['slug' => 'throwaway', '--username' => 'reader'], CreateDatabaseUserRequest::class],
    'destroy' => ['database:destroy', ['slug' => 'throwaway', '--force' => true], DestroyDatabaseConnectionRequest::class],
    'read' => ['database:query', ['slug' => 'throwaway', 'sql' => 'SELECT 1'], QueryDatabaseConnectionRequest::class],
    'write' => ['database:query', ['slug' => 'throwaway', 'sql' => 'CREATE TABLE sample (id integer)', '--write' => true], QueryDatabaseConnectionRequest::class],
]);

it('follows native inspection Operations and renders their terminal JSON result', function (string $name, array $arguments, string $requestClass, array $result): void {
    $home = sys_get_temp_dir().'/database-read-cli-'.bin2hex(random_bytes(8));
    config()->set('orbit.home', $home);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.example.test', '/tmp/unused-ca.pem'));
    $data = ['id' => 17, 'kind' => 'database.read', 'target_type' => 'database_connection', 'target_id' => 2, 'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'result' => $result];
    $envelope = ['data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']];
    MockClient::global([$requestClass => MockResponse::make($envelope, 202), ShowOperationRequest::class => MockResponse::make($envelope), ShowRealtimeRequest::class => MockResponse::make([], 503)]);
    try {
        $tester = new CommandTester(app(Kernel::class)->all()[$name]);
        expect($tester->execute([...$arguments, '--json' => true], ['interactive' => false]))->toBe(0);
        expect(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['id' => 17, 'kind' => 'database.read', 'result' => $result]);
    } finally {
        MockClient::destroyGlobal();
        (new Filesystem)->deleteDirectory($home);
    }
})->with([
    ['database:tables', ['slug' => 'proof'], ListDatabaseTablesRequest::class, ['tables' => ['users']]],
    ['database:schema', ['slug' => 'proof'], ShowDatabaseSchemaRequest::class, ['tables' => [['name' => 'users', 'columns' => []]]]],
    ['database:describe', ['slug' => 'proof', 'table' => 'users'], DescribeDatabaseTableRequest::class, ['table' => 'users', 'columns' => []]],
]);
