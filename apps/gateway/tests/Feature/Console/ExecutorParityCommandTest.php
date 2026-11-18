<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

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
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity', ['--json' => true])
        ->expectsOutputToContain('"ok":true')
        ->assertSuccessful();
});

it('refuses and names each Node that runs another build, lacks the executor or is unreachable', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, str_repeat('d', 64)."\n".str_repeat('d', 64)."\nactive\nfrozen:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "\n\ninactive\nfrozen:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.build_differs, app-dev-2 (#{$this->second->id}): executor.missing")
        ->assertFailed();

    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n\nfailed\nfrozen:\n", '', 1, false)];
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
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n".str_repeat('d', 64)."\nactive\nfrozen:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n", '', 1, false)];

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

it('refuses a frozen container whose Instance the Gateway does not record asleep, and passes once it is', function (): void {
    app(AllocateInstanceBridgeAction::class)->execute($this->first);
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['node_id' => $this->first->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    // A failed Python sleep that left the container frozen: the runtime still records it running, and no sleep owns the freeze.
    $runtime = app(InstanceContainer::class)->reserve($instance, $this->first->refresh());
    $runtime->update(['power_state' => 'running']);
    $sleep = Operation::query()->create(['kind' => InstanceSleep::Kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $this->first->id, 'status' => OperationStatus::Failed, 'error_code' => 'instance.power_helper_failed', 'input' => []]);
    $frozen = "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n{$runtime->container_name}\n";
    $this->reports = ['10.45.0.2' => new CommandResult(0, $frozen, '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): instance.frozen_unrecorded (Instance {$instance->id}). Keep ORBIT_EXECUTOR_WAKE_ENABLED"
            .' and ORBIT_EXECUTOR_POWER_ENABLED off. Wake each listed Instance with the flags off, then check again.')
        ->assertFailed();

    // A recorded freeze is asleep with an owner, so the same FROZEN container passes.
    $runtime->update(['power_state' => 'stopped']);
    $sleep->update(['status' => OperationStatus::Succeeded, 'error_code' => null, 'result' => ['sleep' => 'frozen']]);
    $this->artisan('orbit:executor-parity', ['--json' => true])->expectsOutputToContain('"ok":true')->assertSuccessful();
});

it('refuses a Node whose Incus list failed, rather than passing it unread', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.node_unreachable")->assertFailed();
});
