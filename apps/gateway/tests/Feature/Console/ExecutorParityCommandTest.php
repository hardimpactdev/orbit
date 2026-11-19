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
use App\Infrastructure\Executor\ExecutorParity;
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
use Symfony\Component\Process\Process;

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
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity', ['--json' => true])
        ->expectsOutputToContain('"ok":true')
        ->assertSuccessful();
});

it('refuses and names each Node that runs another build, lacks the executor or is unreachable', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, str_repeat('d', 64)."\n".str_repeat('d', 64)."\nactive\nfrozen:\ncaddy:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "\n\ninactive\nfrozen:\ncaddy:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.build_differs, app-dev-2 (#{$this->second->id}): executor.missing")
        ->assertFailed();

    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n\nfailed\nfrozen:\ncaddy:\n", '', 1, false)];
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
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n".str_repeat('d', 64)."\nactive\nfrozen:\ncaddy:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n", '', 1, false)];

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
    $frozen = "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n{$runtime->container_name}\ncaddy:\n";
    $this->reports = ['10.45.0.2' => new CommandResult(0, $frozen, '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n", '', 1, false)];

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
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')->expectsOutputToContain("app-dev-1 (#{$this->first->id}): executor.node_unreachable")->assertFailed();
});

/** An activation proxy as the nested `app-dev` Node's Caddy loads it for Instance 2, with the address its upstream resolves to. */
function parityProxy(array $change = [], ?string $without = null): string
{
    $proxy = array_replace_recursive(['site' => 'proofapp.nested-dev on srv0 route 0', 'instance' => 2, 'handler' => 'reverse_proxy', 'headers' => ['request' => ['set' => ['Host' => ['gateway.orbit'],
        'X-Forwarded-Method' => ['{http.request.method}'], 'X-Forwarded-Uri' => ['{http.request.uri}']]]], 'resolved' => ['10.45.0.1'],
        'rewrite' => ['method' => 'GET', 'uri' => '/api/v1/runtime-activations/app-instance/2'], 'transport' => ['protocol' => 'http',
            'tls' => ['root_ca_pem_files' => ['/usr/local/share/ca-certificates/orbit-managed-root-ca.crt'], 'server_name' => 'gateway.orbit']],
        'upstreams' => [['dial' => 'gateway.orbit:443']]], $change);
    unset($proxy[$without]);

    return json_encode($proxy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('passes an activation proxy that reaches this Gateway as the route renderer draws it, and names each wrong one', function (): void {
    parityNode('gateway', '10.45.0.1', RoleName::Gateway);
    $report = fn (string ...$proxies): CommandResult => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n".implode("\n", $proxies)."\n", '', 1, false);
    $this->reports = ['10.45.0.2' => $report(parityProxy()), '10.45.0.4' => $report()];
    $this->artisan('orbit:executor-parity', ['--json' => true])->expectsOutputToContain('"ok":true')->assertSuccessful();

    $this->reports['10.45.0.2'] = $report(parityProxy(['rewrite' => ['method' => 'POST']]));
    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain("app-dev-1 (#{$this->first->id}): caddy.activation_proxy_wrong (Instance 2 at proofapp.nested-dev on srv0 route 0: method \"POST\"). Keep")
        ->assertFailed();

    // Another Gateway: a literal upstream, or the right name resolving elsewhere on the Node.
    $this->reports['10.45.0.2'] = $report(parityProxy(['upstreams' => [['dial' => '10.45.0.9:443']], 'resolved' => ['10.45.0.9']]));
    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain('(Instance 2 at proofapp.nested-dev on srv0 route 0: resolves to ["10.45.0.9"]; Instance 2 at proofapp.nested-dev on srv0 route 0: upstream ["10.45.0.9:443"])')
        ->assertFailed();
    // Any address but this Gateway's, even beside it, refuses.
    $this->reports['10.45.0.2'] = $report(parityProxy(['resolved' => ['10.45.0.1', '10.45.0.9']]));
    $this->artisan('orbit:executor-parity')->expectsOutputToContain('(Instance 2 at proofapp.nested-dev on srv0 route 0: resolves to ["10.45.0.1","10.45.0.9"])')->assertFailed();

    // The asleep-marker route finds the proxy, so a removed or wrong rewrite refuses too.
    $this->reports['10.45.0.2'] = $report(parityProxy(without: 'rewrite'));
    $this->artisan('orbit:executor-parity')->expectsOutputToContain('(Instance 2 at proofapp.nested-dev on srv0 route 0: URI null; Instance 2 at proofapp.nested-dev on srv0 route 0: method null)')->assertFailed();
    $this->reports['10.45.0.2'] = $report(parityProxy(['rewrite' => ['uri' => '/api/v1/runtime-activations/app-instance/3']]));
    $this->artisan('orbit:executor-parity')->expectsOutputToContain('(Instance 2 at proofapp.nested-dev on srv0 route 0: URI "/api/v1/runtime-activations/app-instance/3")')->assertFailed();

    // Each site of an Instance counts: a right first site does not cover a broken second one.
    $this->reports['10.45.0.2'] = $report(parityProxy(), parityProxy(['site' => 'proofapp.nested-dev on srv1 route 0'], 'rewrite'));
    $this->artisan('orbit:executor-parity')
        ->expectsOutputToContain('(Instance 2 at proofapp.nested-dev on srv1 route 0: URI null; Instance 2 at proofapp.nested-dev on srv1 route 0: method null)')
        ->assertFailed();
});

it('refuses a Node whose loaded Caddy config it could not read', function (): void {
    $this->reports = ['10.45.0.2' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\n", '', 1, false),
        '10.45.0.4' => new CommandResult(0, "{$this->pinned}\n{$this->pinned}\nactive\nfrozen:\ncaddy:\n", '', 1, false)];

    $this->artisan('orbit:executor-parity')->expectsOutputToContain("app-dev-1 (#{$this->first->id}): caddy.config_unreadable")->assertFailed();
});

it('finds the activation proxy of every site on the Node by its asleep-marker route, with or without a rewrite', function (): void {
    $asleep = static fn (int $id): array => [['file' => ['root' => '/data/caddy/orbit/hibernation', 'try_files' => ["/app-instance-{$id}.asleep"]],
        'not' => [['header' => ['X-Orbit-Probe' => ['1']]]]]];
    $proxy = ['handler' => 'reverse_proxy', 'rewrite' => ['method' => 'GET', 'uri' => '/api/v1/runtime-activations/app-instance/2'],
        'upstreams' => [['dial' => '127.0.0.1:443']], 'handle_response' => [['routes' => []]]];
    $site = static fn (string $host, int $id, array $handler): array => ['match' => [['host' => [$host]]], 'handle' => [['handler' => 'subroute', 'routes' => [
        ['match' => $asleep($id), 'handle' => [['handler' => 'vars', 'log_skip' => true]]],
        ['handle' => [['handler' => 'subroute', 'routes' => [['match' => $asleep($id), 'handle' => [$handler]]]]]],
        ['handle' => [['handler' => 'reverse_proxy', 'rewrite' => ['uri' => '/api/v1/runtime-activations/app-instance/9'], 'upstreams' => [['dial' => 'unix//run/php.sock']]]]],
    ]]], 'terminal' => true];
    // One Instance on three sites: a second host, and the same host on a second listener (bridge and WireGuard). Each is read on
    // its own, so a right first site covers neither one whose proxy lost its rewrite.
    $unrewritten = ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:443']]];
    $config = ['apps' => ['http' => ['servers' => ['srv0' => ['routes' => [$site('b.test', 2, $unrewritten), $site('a.test', 2, $proxy)]],
        'srv1' => ['routes' => [$site('a.test', 2, $unrewritten)]]]]]];
    $process = new Process(['python3', '-I', '-c', (new ReflectionClassConstant(ExecutorParity::class, 'Proxies'))->getValue()]);
    $process->setInput(json_encode($config, JSON_THROW_ON_ERROR))->mustRun();

    unset($proxy['handle_response']);
    expect(array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", trim($process->getOutput()))))
        ->toEqual([[...$proxy, 'site' => 'a.test on srv0 route 1', 'instance' => 2, 'resolved' => ['127.0.0.1']],
            [...$unrewritten, 'site' => 'a.test on srv1 route 0', 'instance' => 2, 'resolved' => ['127.0.0.1']],
            [...$unrewritten, 'site' => 'b.test on srv0 route 0', 'instance' => 2, 'resolved' => ['127.0.0.1']]]);
    // The read passes the script inside single quotes to `sh -c`.
    expect((new ReflectionClassConstant(ExecutorParity::class, 'Proxies'))->getValue())->not->toContain("'");
});
