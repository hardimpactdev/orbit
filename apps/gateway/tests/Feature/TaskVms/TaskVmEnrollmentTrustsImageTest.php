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
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\Roles\DevelopmentRoleBaseline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\VpToolManager;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;

it('trusts only a linked task VM image and still applies VM-specific infrastructure', function (bool $taskVm): void {
    // The ordinary Node deliberately has a VM-looking name: identity is the row link, not a name convention.
    $node = Node::query()->create([
        'name' => 'tvm-12', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux',
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

            $output = $command->arguments === ['uname', '-m'] ? "x86_64\n" : "1.0.0\n";
            if (str_contains($command->input ?? '', 'Orbit Vite Plus scope')) {
                $output = "/opt/orbit/vite-plus/bin/vp\n";
            }

            return new CommandResult(0, $output, '', 1, false);
        }
    };
    app()->instance(SshExecutor::class, $ssh);
    $agents = (new FakeAgentCommandRunner)->answer('caddy.install-package', ['changed' => false, 'version' => '2.10.2'])->answer('role.install-prerequisites', [])->answer('recipe.install-javascript', ['changed' => false, 'retired' => []])
        ->answer('node.bootstrap', [])->answer('agent.status', ['version' => '0.5.0', 'sha256' => str_repeat('a', 64), 'platform' => 'linux', 'architecture' => 'x86_64', 'observer' => 'active'])
        ->answer('hibernation.prepare-directories', [])
        ->answer('tool.materialize-composer', [])
        ->answer('tool.materialize-vp', ['binary' => '/opt/orbit/vite-plus/bin/vp', 'changed' => false])
        ->answer('tool.composer-version', ['version' => 'Composer version 2.9.0'])
        ->answer('tool.vp-version', ['version' => 'vp v0.3.3'])
        ->answer('tool.vp-install', [])->answer('tool.vp-update', [])
        ->answer('tool.inspect-vp-scope', ['binary' => '/opt/orbit/vite-plus/bin/vp']);
    app()->instance(AgentCommandRunner::class, $agents);
    $admission = Mockery::mock(AdmissionAgentRunner::class);
    $admission->shouldReceive('architectureOver')->once()->andReturn('x86_64');
    $admission->shouldReceive('runOver')->andReturnUsing(fn (Node $target, SshConnection $connection, string $command, array $input = []) => $agents->run($target, $command, $input));
    $admission->shouldReceive('installOver')->once()->with($node, Mockery::type(SshConnection::class))->andReturn(false);
    app()->instance(AdmissionAgentRunner::class, $admission);
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

    $agentPrograms = ['bootstrap' => 'node.bootstrap', 'caddy-package' => 'caddy.install-package', 'app-dev-prerequisites' => 'role.install-prerequisites', 'vp-manager' => 'tool.materialize-vp', 'composer-manager' => 'tool.materialize-composer'];

    app(NativeNodeConverger::class)->converge($node, new NodeProvisioningIdentity('orbit', 'orbit'), 'SHA256:pinned');
    app(DevelopmentRoleBaseline::class)->converge($node, $assignment);
    app(NativeToolManagerMaterializer::class)->convergeWithFailureHandler($node, static function (): void {}, ToolManagerName::Composer, ToolManagerName::Vp);

    foreach ($agentPrograms as $name => $command) {
        expect(array_keys($agents->commands(), $command, true), $name)->toHaveCount($taskVm ? 0 : 1);
        if ($taskVm) {
            Log::shouldHaveReceived('info')->with('Task VM program skipped: its image ran it.', ['task_vm' => $node->name, 'program' => $name])->once();
        }
    }
    if (! $taskVm) {
        Log::shouldNotHaveReceived('info', ['Task VM program skipped: its image ran it.', Mockery::any()]);
    }
    // Explicit manager install/update operations must not inherit enrollment's image trust.
    app(ComposerToolManager::class)->materialize($node);
    app(VpToolManager::class)->materialize($node);
    expect(array_keys($agents->commands(), 'tool.materialize-composer', true))->toHaveCount($taskVm ? 1 : 2);
    expect(array_keys($agents->commands(), 'tool.materialize-vp', true))->toHaveCount($taskVm ? 1 : 2);
    $agents->answer('tool.composer-install', []);
    $agents->answer('tool.composer-update', []);
    app(ComposerToolManager::class)->install($node, 'vendor/package');
    app(ComposerToolManager::class)->update($node, 'vendor/package');
    expect(array_slice($agents->calls, -2))->toBe([
        ['node' => $node->name, 'command' => 'tool.composer-install', 'input' => ['package' => 'vendor/package']],
        ['node' => $node->name, 'command' => 'tool.composer-update', 'input' => ['package' => 'vendor/package']],
    ]);
    app(VpToolManager::class)->install($node, '@vendor/package');
    app(VpToolManager::class)->update($node, '@vendor/package');
    expect(array_slice($agents->calls, -2))->toBe([
        ['node' => $node->name, 'command' => 'tool.vp-install', 'input' => ['package' => '@vendor/package']],
        ['node' => $node->name, 'command' => 'tool.vp-update', 'input' => ['package' => '@vendor/package']],
    ]);
    expect($node->fresh()->ssh_host_fingerprint)->toBe('SHA256:pinned');
    // Hibernation directories are per-VM state, not part of the software skip.
    expect(array_values(array_filter($agents->calls, static fn (array $call): bool => $call['command'] === 'hibernation.prepare-directories')))->toBe([
        ['node' => $node->name, 'command' => 'hibernation.prepare-directories', 'input' => [
            'marker_directory' => RuntimeHibernation::MarkerDirectory,
            'log_directory' => RuntimeHibernation::AccessLogDirectory,
        ]],
    ]);
})->with(['task VM without metadata' => true, 'ordinary app-dev Node' => false]);
