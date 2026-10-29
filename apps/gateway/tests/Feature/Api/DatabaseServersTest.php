<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);

    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->dbNode = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
});

/** @return array<string, mixed> */
function database_server_request(Node $node, array $overrides = []): array
{
    return ['slug' => 'beast-mysql', 'node_id' => $node->id, ...$overrides];
}

function database_server_root_password(): string
{
    return DatabaseServer::query()->where('slug', 'beast-mysql')->sole()->root_password;
}

describe('database:server:create', function (): void {

    it('refuses another Node, tag, or port for an existing slug', function (array $overrides): void {
        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))->assertAccepted();

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode, $overrides))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');
    })->with([
        'tag' => [['tag' => '9.0']],
        'port' => [['port' => 3307]],
    ]);

    it('refuses a port that another Process on the Node publishes', function (): void {
        Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->dbNode->id,
            'name' => 'mysql',
            'runtime' => ProcessRuntime::Docker,
            'working_directory' => '/app',
            'runtime_config' => [
                'image' => 'mysql:8.4',
                'command' => ['mysqld'],
                'environment' => [],
                'ports' => ['127.0.0.1:3306:3306/tcp'],
                'volumes' => [],
            ],
            'restart_policy' => 'unless-stopped',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_port_in_use');

        expect(DatabaseServer::query()->exists())->toBeFalse();
    });

    it('validates the request body', function (array $body): void {
        $this->postJson('/api/v1/database-servers', $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseServer::query()->exists())->toBeFalse();
    })->with([
        'unknown key' => fn (): array => database_server_request($this->dbNode, ['environment' => ['A' => 'b']]),
        'bad slug' => fn (): array => database_server_request($this->dbNode, ['slug' => 'Beast_MySQL']),
        'bad tag' => fn (): array => database_server_request($this->dbNode, ['tag' => '8.4; rm -rf /']),
        'tag starting with a dot' => fn (): array => database_server_request($this->dbNode, ['tag' => '.8.4']),
        'version instead of tag' => fn (): array => database_server_request($this->dbNode, ['version' => '8.4']),
        'string port' => fn (): array => database_server_request($this->dbNode, ['port' => '3306']),
        'missing node' => fn (): array => ['slug' => 'beast-mysql'],
    ]);
});

describe('database:server list, show, and destroy', function (): void {
    beforeEach(function (): void {
        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))->assertAccepted();
        $this->server = DatabaseServer::query()->sole();
    });

    it('lists servers and shows one with its databases', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'shop',
            'driver' => 'mysql',
            'node_id' => $this->dbNode->id,
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'shop',
            'password' => 'shop-secret',
        ]);

        $this->getJson('/api/v1/database-servers')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'beast-mysql')
            ->assertJsonPath('data.0.databases_count', 1)
            ->assertDontSee($this->server->root_password);

        $this->getJson('/api/v1/database-servers/beast-mysql')
            ->assertOk()
            ->assertJsonPath('data.databases.0.slug', 'shop')
            ->assertJsonPath('data.databases.0.server', 'beast-mysql')
            ->assertDontSee($this->server->root_password)
            ->assertDontSee('shop-secret');

        $this->getJson('/api/v1/database-servers/missing')->assertNotFound();
    });

    it('refuses to remove a server that a connection points to', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'shop',
            'driver' => 'mysql',
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'database' => 'shop',
            'username' => 'shop',
            'password' => 'shop-secret',
        ]);

        $this->deleteJson('/api/v1/database-servers/beast-mysql')
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_in_use');

        expect($this->runtime->removed)->toBe([])
            ->and(DatabaseServer::query()->exists())->toBeTrue();
    });

});

it('rejects PostgreSQL images outside major 18 before reserving a server', function (string $tag): void {
    $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode, ['driver' => 'pgsql', 'tag' => $tag]))
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(DatabaseServer::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0);
})->with(['16', '17-alpine', '19', 'latest', '180']);

it('refuses wildcard and public publication addresses before creating a server', function (string $field, string $address, string $driver): void {
    $this->dbNode->update([$field => $address]);
    $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode, ['driver' => $driver]))
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(DatabaseServer::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0);
})->with([
    ['wireguard_ip', '0.0.0.0'], ['lan_ip', '0.0.0.0'],
    ['wireguard_ip', '85.9.217.155'], ['lan_ip', '85.9.217.155'],
    ['lan_ip', '::'],
])->with(['mysql', 'pgsql', 'redis']);
