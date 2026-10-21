<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

it('publishes private DNS on the VPN node when gateway and vpn are split', function (): void {
    $vpn = dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();

    expect($processes->calls)
        ->toBe(0)
        ->and($ssh->hosts)
        ->toBe(['10.44.0.1'])
        ->and($ssh->users)
        ->toBe([$vpn->user])
        ->and($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu'])
        ->and($ssh->commands[0]->input)
        ->toContain('orbit-records.conf')
        ->toContain('catalog.json')
        ->toContain('catalog.json.loaded')
        ->toContain('listen_addr=10.44.0.1')
        ->toContain('/var/lib/orbit/private-dns/releases/'.PrivateDnsListenerRelease::fromGateway()->id())
        ->toContain('serve.php" --self-test')
        ->toContain('systemctl start orbit-private-dns.socket')
        ->toContain('systemctl restart orbit-private-dns.service')
        ->not->toContain('orbit:private-dns-serve');
});

it('publishes on the VPN node while the gateway or vpn role converges', function (RoleName $converging): void {
    dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    // `node:role:add --converge` claims the existing assignment as provisioning while it runs.
    Node::query()->whereHas('roles', static fn ($query) => $query->where('role', $converging))->sole()
        ->roles()->where('role', $converging)->update(['status' => LifecycleStatus::Provisioning]);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();

    expect($processes->calls)->toBe(0)
        ->and($ssh->hosts)->toBe(['10.44.0.1']);
})->with([
    'gateway role converging' => [RoleName::Gateway],
    'vpn role converging' => [RoleName::Vpn],
]);

it('publishes on the VPN node while node:add marks the gateway or vpn node provisioning', function (RoleName $reprovisioned): void {
    dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    Node::query()->whereHas('roles', static fn ($query) => $query->where('role', $reprovisioned))->sole()
        ->update(['status' => LifecycleStatus::Provisioning]);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();

    expect($processes->calls)->toBe(0)
        ->and($ssh->hosts)->toBe(['10.44.0.1']);
})->with([
    'gateway node provisioning' => [RoleName::Gateway],
    'vpn node provisioning' => [RoleName::Vpn],
]);

it('installs the listener from its release on a remote vpn node, not from a Gateway checkout', function (): void {
    dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();
    $input = $ssh->commands[0]->input;
    $release = PrivateDnsListenerRelease::fromGateway();

    expect($processes->calls)->toBe(0)
        ->and($input)
        ->toContain(base64_encode($release->files()['serve.php']))
        ->toContain(base64_encode($release->files()['app/Infrastructure/AppDev/PrivateDnsListenerProcess.php']))
        ->not->toContain('artisan')
        ->not->toContain(base_path());
});

it('publishes through pinned self SSH when gateway and vpn share a node', function (LifecycleStatus $status): void {
    $node = dns_target_node('gateway', '10.44.0.1', RoleName::Gateway);
    $node->roles()->create([
        'role' => RoleName::Vpn,
        'status' => $status,
    ]);
    $node->update(['status' => $status]);
    $node->roles()->update(['status' => $status]);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();

    expect($processes->calls)
        ->toBe(0)
        ->and($ssh->hosts)
        ->toBe(['10.44.0.1'])
        ->and($ssh->connections[0]->identityFile)->toBe('/tmp/orbit-dns.key')
        ->and($ssh->connections[0]->knownHostsFile)->toBe('/tmp/orbit-dns-known-hosts')
        ->and($ssh->users)->toBe([$node->user]);
})->with([LifecycleStatus::Active, LifecycleStatus::Provisioning]);

it('fails SSH publication without falling back to the local process runner', function (bool $shared): void {
    $vpn = dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    if ($shared) {
        $vpn->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    } else {
        dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    }
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor(succeed: false);
    Log::spy();

    expect(fn () => dns_target_manager($processes, $ssh)->converge())
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.dns_config_failed')
                ->and($exception->getMessage())
                ->toContain('vpn');
        })
        ->and($processes->calls)
        ->toBe(0);

    Log::shouldHaveReceived('warning')->once()->with('Private DNS publication failed.', [
        'node_id' => $vpn->id, 'exit_code' => 1, 'duration_ms' => 1,
        'stdout' => 'remote validation output', 'stderr' => 'dnsmasq failed', 'output_truncated' => false,
    ]);
})->with(['shared Node' => true, 'split Nodes' => false]);

function dns_target_manager(ProcessRunner $processes, SshExecutor $ssh): DnsmasqPrivateDnsManager
{
    return new DnsmasqPrivateDnsManager(
        processes: $processes,
        renderer: new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class)),
        activateListener: true,
        ssh: $ssh,
        keys: new DnsTargetSshKeyProvider,
        knownHosts: new DnsTargetKnownHostsStore,
    );
}

function dns_target_node(string $name, string $address, RoleName $role): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
    $node->roles()->create([
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

final class DnsTargetProcessRunner implements ProcessRunner
{
    public int $calls = 0;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls++;

        return new CommandResult(0, '', '', 1, false);
    }
}

final class DnsTargetSshExecutor implements SshExecutor
{
    /** @var list<string> */
    public array $hosts = [];

    /** @var list<string> */
    public array $users = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(
        private bool $succeed = true,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->hosts[] = $connection->host;
        $this->users[] = $connection->user;
        $this->commands[] = $command;

        return new CommandResult($this->succeed ? 0 : 1, $this->succeed ? '' : 'remote validation output', $this->succeed ? '' : 'dnsmasq failed', 1, false);
    }
}

final readonly class DnsTargetSshKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/orbit-dns.key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 dns';
    }
}

final readonly class DnsTargetKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/orbit-dns-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
