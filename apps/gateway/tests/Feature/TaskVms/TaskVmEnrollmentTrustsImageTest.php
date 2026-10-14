<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\Roles\DevelopmentRoleBaseline;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\VpToolManager;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;

it('trusts only a linked task VM image and still applies VM-specific infrastructure', function (bool $taskVm): void {
    // The ordinary Node deliberately has a VM-looking name: identity is the row link, not a name convention.
    $node = Node::query()->create([
        'name' => 'tvm-12', 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux',
        'public_ssh_host' => '10.251.77.12', 'wireguard_ip' => '10.44.0.140', 'user' => 'orbit',
    ]);
    $assignment = $node->roles()->create(['role' => RoleName::AppDev, 'state' => LifecycleStatus::Provisioning]);
    if ($taskVm) {
        TaskVm::query()->create([
            'name' => 'tvm-12', 'provider' => 'incus', 'node_id' => $node->id, 'state' => TaskVmState::Provisioning,
            'wireguard_ip' => $node->wireguard_ip,
        ]);
    }
    Log::spy();
    $ssh = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult(0, $command->arguments === ['uname', '-m'] ? "x86_64\n" : '', '', 1, false);
        }
    };
    app()->instance(SshExecutor::class, $ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/tmp/orbit-test-key');
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 TEST');
    app()->instance(SshKeyProvider::class, $keys);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn(new HostKey('ssh-ed25519', 'PUBLICKEY', 'SHA256:pinned'));
    app()->instance(HostKeyScanner::class, $scanner);
    $knownHosts = Mockery::mock(KnownHostsStore::class);
    $knownHosts->shouldReceive('path')->andReturn('/tmp/orbit-known-hosts');
    $knownHosts->shouldReceive('put')->twice();
    app()->instance(KnownHostsStore::class, $knownHosts);
    $wireGuard = Mockery::mock(WireGuardPeerConverger::class);
    $wireGuard->shouldReceive('converge')->once();
    app()->instance(WireGuardPeerConverger::class, $wireGuard);
    $firewall = Mockery::mock(NodeRoleFirewallManager::class);
    $firewall->shouldReceive('trustWireGuardMembers')->once();
    $firewall->shouldReceive('converge')->once()->with($node, RoleName::AppDev, 'orbit');
    app()->instance(NodeRoleFirewallManager::class, $firewall);
    $caddy = Mockery::mock(AppDevCaddyManager::class);
    $caddy->shouldReceive('converge')->once()->with($node, RoleName::AppDev);
    app()->instance(AppDevCaddyManager::class, $caddy);
    $dns = Mockery::mock(PrivateDnsManager::class);
    $dns->shouldReceive('converge')->once()->with($node);
    app()->instance(PrivateDnsManager::class, $dns);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $storage = Mockery::mock(NodeStorageRootPreparer::class);
    $storage->shouldReceive('prepare')->once();
    app()->instance(NodeStorageRootPreparer::class, $storage);

    $commands = app(NodeRolePrerequisiteCommandFactory::class);
    $programs = [
        'bootstrap' => app(NodeBootstrapCommandFactory::class)->makeWithPasswordlessSudo($node, 'orbit'),
        'caddy-package' => $commands->caddyPackage($node, RoleName::AppDev),
        'app-dev-prerequisites' => $commands->make($node, RoleName::AppDev, new ManagedUserAccount('orbit', 'orbit', '/home/orbit')),
        'composer-manager' => app(ComposerToolManager::class)->materializeCommand($node),
        'vp-manager' => app(VpToolManager::class)->materializeCommand($node),
    ];

    app(NativeNodeConverger::class)->converge($node, new NodeProvisioningIdentity('orbit', 'orbit'), 'SHA256:pinned');
    app(DevelopmentRoleBaseline::class)->converge($node, $assignment);
    app(ComposerToolManager::class)->materialize($node);
    app(VpToolManager::class)->materialize($node);

    foreach ($programs as $name => $program) {
        $executed = array_filter($ssh->commands, static fn (RemoteCommand $command): bool => $command->arguments === $program->arguments && $command->input === $program->input);
        expect($executed, $name)->toHaveCount($taskVm ? 0 : 1);
        if ($taskVm) {
            Log::shouldHaveReceived('info')->with('Task VM program skipped: its image ran it.', ['task_vm' => $node->name, 'program' => $name])->once();
        }
    }
    if (! $taskVm) {
        Log::shouldNotHaveReceived('info', ['Task VM program skipped: its image ran it.', Mockery::any()]);
    }
    expect($node->fresh()->ssh_host_fingerprint)->toBe('SHA256:pinned');
    // Hibernation directories are per-VM state, not part of the software skip.
    expect(collect($ssh->commands)->contains(static fn (RemoteCommand $command): bool => in_array(RuntimeHibernation::MarkerDirectory, $command->arguments, true)))->toBeTrue();
})->with(['task VM without metadata' => true, 'ordinary app-dev Node' => false]);
