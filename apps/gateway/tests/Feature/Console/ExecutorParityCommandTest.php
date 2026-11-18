<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function parityNode(string $name, string $ip, RoleName $role): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => "{$name}-key",
        'ssh_host_fingerprint' => "SHA256:{$name}"]));
    $node->roles()->create(['role' => $role, 'status' => 'active']);

    return $node;
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->mock(WebSocketClient::class, fn ($mock) => $mock->shouldNotReceive('connect'));
    $this->mock(SshKeyProvider::class, fn ($mock) => $mock->shouldReceive('privateKeyPath')->andReturn('/fixture/key'));
    $this->mock(KnownHostsStore::class, fn ($mock) => $mock->shouldReceive('path')->andReturn('/fixture/known_hosts'));
    $this->pinned = str_repeat('e', 64);
    $this->manifest = tempnam(sys_get_temp_dir(), 'agent-manifest-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '1.2.3', 'artifacts' => [
        'linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent'],
        'executor-linux-x86_64' => ['sha256' => $this->pinned, 'url' => 'https://artifacts.example/executor'],
    ]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, '/unused'));
    config(['orbit.executor.public_keys' => [base64_encode(str_repeat('k', 32))]]);
    parityNode('app-prod', '10.45.0.3', RoleName::AppProd);
    $this->first = parityNode('app-dev-1', '10.45.0.2', RoleName::AppDev);
    $this->second = parityNode('app-dev-2', '10.45.0.4', RoleName::AppDev);
    $this->reports = [];
    $this->mock(SshExecutor::class, fn ($mock) => $mock->shouldReceive('execute')
        ->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            expect($command->arguments[0])->toBe('sh')->and($command->arguments[2])->toContain('sha256sum /usr/local/libexec/orbit-executor')
                ->and($command->arguments[2])->toContain('sudo -n sha256sum "/proc/$pid/exe"');

            return $this->reports[$connection->host] ?? new CommandResult(255, '', 'unreachable', 1, false);
        }));
});

afterEach(function (): void {
    @unlink($this->manifest);
});

it('passes when every app-dev Node runs the pinned executor build, and reads no other Node', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\n", '', 1, false)];

    $this->artisan('orbit:executor-parity', ['--json' => true])
        ->expectsOutputToContain('"ok":true')
        ->assertSuccessful();
});

it('refuses and names each Node that runs another build, lacks the executor or is unreachable', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, str_repeat('d', 64)."\n".str_repeat('d', 64)."\nactive\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "\n\ninactive\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.build_differs, app-dev-2 (#{$this->second->id}): executor.missing")
        ->assertFailed();

    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n\nfailed\n", '', 1, false)];
    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.inactive, app-dev-2 (#{$this->second->id}): executor.node_unreachable")
        ->assertFailed();
});

it('refuses while the converge would skip the install', function (): void {
    config(['orbit.executor.public_keys' => []]);

    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain('executor.keys_missing')
        ->assertFailed();
});

it('refuses a Node whose service still runs another build than the installed pinned binary', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n".str_repeat('d', 64)."\nactive\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.running_build_differs")
        ->assertFailed();
    $this->artisan('orbit:executor-parity', ['--json' => true])
        ->doesntExpectOutputToContain('Executor parity fails')
        ->expectsOutputToContain('"problem":"executor.running_build_differs"')
        ->assertFailed();
});

it('refuses when it checked no executor Node', function (): void {
    Node::query()->whereIn('id', [$this->first->id, $this->second->id])->update(['status' => 'removing']);

    $this->artisan('orbit:executor-parity')->expectsOutputToContain('checked no executor Node')->assertFailed();
    $this->artisan('orbit:executor-parity', ['--json' => true])->expectsOutputToContain('"ok":false')->assertFailed();
});
