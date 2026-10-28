<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

it('follows database Operations and forwards the start key without reading a deleted target', function (string $name, array $arguments, string $requestClass): void {
    $home = sys_get_temp_dir().'/database-swamp-cli-'.bin2hex(random_bytes(8));
    config()->set('orbit.home', $home);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.example.test', '/tmp/unused-ca.pem'));
    $data = ['id' => 7, 'kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => 2,
        'status' => 'succeeded', 'sequence' => 3, 'input' => ['intent_id' => 9], 'result' => ['backend' => 'active']];
    $envelope = ['data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']];
    $mock = MockClient::global([$requestClass => MockResponse::make($envelope, 202), ShowOperationRequest::class => MockResponse::make($envelope), ShowRealtimeRequest::class => MockResponse::make([], 503)]);
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
    'server-create' => ['database:server:create', ['slug' => 'dev-db', '--node' => '7'], CreateDatabaseServerRequest::class],
    'server-close' => ['database:server:close', ['slug' => 'dev-db'], CloseDatabaseServerRequest::class],
    'server-destroy' => ['database:server:destroy', ['slug' => 'dev-db', '--force' => true], DestroyDatabaseServerRequest::class],
    'create' => ['database:create', ['slug' => 'throwaway', '--server' => 'dev-db'], CreateDatabaseConnectionRequest::class],
    'user-create' => ['database:user:create', ['slug' => 'throwaway', '--username' => 'reader', '--password' => 'synthetic-password'], CreateDatabaseUserRequest::class],
    'destroy' => ['database:destroy', ['slug' => 'throwaway', '--force' => true], DestroyDatabaseConnectionRequest::class],
    'write' => ['database:query', ['slug' => 'throwaway', 'sql' => 'CREATE TABLE sample (id integer)', '--write' => true], QueryDatabaseConnectionRequest::class],
]);
