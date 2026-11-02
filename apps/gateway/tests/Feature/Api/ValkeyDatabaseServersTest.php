<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeValkeyAclAgent;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->programs = new FakeValkeyAclAgent(app(AgentCommandRunner::class));
    app()->instance(AgentCommandRunner::class, $this->programs);

    $gateway = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND',
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
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => ($sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-create', 'runId' => Str::uuid()->toString()]]]);
    app()->instance(WebSocketClient::class, $socket);

    $this->dbNode = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND',
        'name' => 'beast',
        'architecture' => 'amd64',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
});

/** @return array<string, mixed> */
function valkey_server_request(Node $node, array $overrides = []): array
{
    return ['slug' => 'beast-valkey', 'node_id' => $node->id, 'driver' => 'redis', ...$overrides];
}

describe('database:server:create --driver=redis', function (): void {

    it('refuses the slug of a server with another driver', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-db', 'node_id' => $this->dbNode->id, 'port' => 6379])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'database_server.create');

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode, ['slug' => 'beast-db', 'tag' => '8.4', 'port' => 6379]))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');

        expect(DatabaseServer::query()->sole()->driver->value)->toBe('mysql')
            ->and($this->programs->runs)->toBe([]);
    });

    it('validates the driver', function (array $body): void {
        $this->postJson('/api/v1/database-servers', $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseServer::query()->exists())->toBeFalse()
            ->and($this->programs->runs)->toBe([]);
    })->with([
        'pgsql' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'sqlite']),
        'sqlite' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'sqlite']),
        'unknown driver' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'valkey']),
    ]);

    it('lists and shows the driver of each server', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $this->dbNode->id])->assertAccepted();
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertAccepted();

        $this->getJson('/api/v1/database-servers')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'beast-mysql')
            ->assertJsonPath('data.0.driver', 'mysql')
            ->assertJsonPath('data.1.slug', 'beast-valkey')
            ->assertJsonPath('data.1.driver', 'redis');

        $this->getJson('/api/v1/database-servers/beast-valkey')
            ->assertOk()
            ->assertJsonPath('data.driver', 'redis')
            ->assertJsonPath('data.databases', []);
    });
});

describe('the Process of a Valkey server', function (): void {
    beforeEach(function (): void {
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertAccepted();
        $this->server = DatabaseServer::query()->sole();
        $this->process = Process::query()->sole();
    });

    it('refuses process:update before it changes anything', function (): void {
        $before = $this->process->getAttributes();

        $this->patchJson("/api/v1/processes/{$this->process->id}", [
            'runtime' => 'docker',
            'image' => 'valkey/valkey:8.1',
            'command' => ['valkey-server', '--appendonly', 'yes'],
            'environment' => ['VALKEY_EXTRA_FLAGS' => '--aclfile /dev/null'],
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'process.required_by_database_server');

        expect($this->process->refresh()->getAttributes())->toBe($before)
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    });

});

describe('database:server:close boundary', function (): void {
    it('refuses MySQL and missing servers', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $this->dbNode->id])->assertAccepted();
        $this->postJson('/api/v1/database-servers/beast-mysql/close')->assertUnprocessable()->assertJsonPath('error.code', 'database.driver_unsupported');
        $this->postJson('/api/v1/database-servers/missing/close')->assertNotFound();
    });

    it('requires an active peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/database-servers/missing/close')->assertForbidden();
    });
});
