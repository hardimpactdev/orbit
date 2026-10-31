<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-database-server-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @return array<class-string, MockResponse> */
function database_server_cli_responses(string $request, string $kind = 'database_server.create'): array
{
    $operation = ['id' => 7, 'kind' => $kind, 'target_type' => 'database_server', 'target_id' => 1,
        'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'result' => ['backend' => 'active']];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']];

    return [$request => MockResponse::make($envelope, 202), ShowOperationRequest::class => MockResponse::make($envelope), ShowRealtimeRequest::class => MockResponse::make([], 503)];
}

describe('database:server commands', function (): void {
    it('resolves the Node by name and sends only the given tag and port', function (): void {
        $mockClient = MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [[
                    'id' => 7,
                    'name' => 'beast',
                    'status' => 'active',
                    'platform' => 'linux',
                    'public_ssh_host' => '192.0.2.80',
                    'public_ssh_port' => 22,
                    'user' => 'orbit',
                    'wireguard_ip' => '10.44.0.80',
                    'roles' => [],
                ]],
                'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
            ]),
            ...database_server_cli_responses(CreateDatabaseServerRequest::class),
        ]);

        $this
            ->artisan('database:server:create', ['slug' => 'beast-mysql', '--node' => 'beast', '--port' => '3307', '--json' => true])
            ->expectsOutputToContain('"kind":"database_server.create"')
            ->assertExitCode(0);

        $mockClient->assertSent(static fn ($request): bool => $request instanceof CreateDatabaseServerRequest
            && $request->body()->all() === ['slug' => 'beast-mysql', 'node_id' => 7, 'port' => 3307]);
    });

    it('sends the MySQL image tag as tag', function (): void {
        $mockClient = MockClient::global(database_server_cli_responses(CreateDatabaseServerRequest::class));

        $this->artisan('database:server:create', ['slug' => 'beast-mysql', '--node' => '7', '--tag' => '8.0', '--json' => true])
            ->assertExitCode(0);

        $mockClient->assertSent(static fn ($request): bool => $request instanceof CreateDatabaseServerRequest
            && $request->body()->all() === ['slug' => 'beast-mysql', 'node_id' => 7, 'tag' => '8.0']);
    });

    it('refuses a JSON destroy without --force and destroys after it', function (): void {
        $mockClient = MockClient::global(database_server_cli_responses(DestroyDatabaseServerRequest::class, 'database_server.destroy'));
        $tester = new CommandTester(app(Kernel::class)->all()['database:server:destroy']);

        expect($tester->execute(['slug' => 'beast-mysql', '--json' => true], ['interactive' => false]))->toBe(1)
            ->and($tester->getDisplay(true))->toContain('database.confirmation_required')
            ->and($mockClient->getLastPendingRequest())->toBeNull();

        $this->artisan('database:server:destroy', ['slug' => 'beast-mysql', '--force' => true, '--json' => true])
            ->assertExitCode(0);

        $mockClient->assertSent(DestroyDatabaseServerRequest::class);
    });
});

it('sends Valkey driver and adoption options', function (): void {
    $mockClient = MockClient::global(database_server_cli_responses(CreateDatabaseServerRequest::class));
    $this->artisan('database:server:create', ['slug' => 'cache', '--node' => '7', '--driver' => 'redis', '--process' => '93', '--connection' => 'cache', '--json' => true])->assertExitCode(0);
    $mockClient->assertSent(static fn ($request): bool => $request instanceof CreateDatabaseServerRequest
        && $request->body()->all() === [
            'slug' => 'cache', 'node_id' => 7, 'driver' => 'redis', 'process_id' => 93, 'connection' => 'cache',
        ]);
});

it('refuses invalid server driver or Process before sending', function (array $options): void {
    $mockClient = MockClient::global([]);
    $this->artisan('database:server:create', ['slug' => 'cache', '--node' => '7', '--json' => true, ...$options])->assertExitCode(1);
    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    [['--driver' => 'sqlite']],
    [['--process' => '0']],
]);

it('sends the close request and preserves the Gateway refusal', function (): void {
    $mockClient = MockClient::global([
        CloseDatabaseServerRequest::class => MockResponse::make([
            'error' => ['code' => 'database.server_in_use', 'message' => 'Clients still use default.', 'request_id' => '11111111-1111-4111-8111-111111111111'],
        ], 409),
    ]);
    $tester = new CommandTester(app(Kernel::class)->all()['database:server:close']);
    expect($tester->execute(['slug' => 'cache', '--json' => true], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay(true))->toContain('database.server_in_use')
        ->and($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/database-servers/cache/close');
});

it('sends PostgreSQL and an explicit port through the SDK', function (): void {
    $mock = MockClient::global(database_server_cli_responses(CreateDatabaseServerRequest::class));
    $tester = new CommandTester(app(Kernel::class)->all()['database:server:create']);
    expect($tester->execute(['slug' => 'services-postgres', '--node' => '17', '--driver' => 'pgsql', '--port' => '5434', '--json' => true]))->toBe(0);
    $mock->assertSent(fn ($request): bool => $request instanceof CreateDatabaseServerRequest && $request->body()->all() === ['slug' => 'services-postgres', 'node_id' => 17, 'port' => 5434, 'driver' => 'pgsql']);
});
