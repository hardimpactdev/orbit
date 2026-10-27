<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\RecoverableWireGuardPeerConverger;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

final class BaseAdmissionAgents implements AdmissionAgentRunner
{
    /** Answers may break their output schema, to test the converger's own guard against a malformed result. */
    public bool $malformed = false;

    public array $calls = [];

    public ?Closure $answer = null;

    public string $architecture = 'x86_64';

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        $this->call($node, $connection, 'stage-architecture', []);

        return $this->architecture;
    }

    public function installOver(Node $node, SshConnection $connection): bool
    {
        $this->call($node, $connection, 'agent.install', []);

        return false;
    }

    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        FakeAgentCommandRunner::admit($node, $command, $input);
        $result = $this->respond($node, $connection, $command, $input, $deadlineSeconds);
        if (! $this->malformed) {
            AgentSchemas::assertOutput($command, $result);
        }

        return $result;
    }

    private function respond(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        return $this->call($node, $connection, $command, $input);
    }

    private function call(Node $node, SshConnection $connection, string $command, array $input): array
    {
        $this->calls[] = compact('connection', 'command', 'input');
        if ($this->answer !== null) {
            return ($this->answer)($node, $connection, $command, $input);
        }

        return $command === 'agent.status' ? base_agent_status($this->architecture) : [];
    }
}

final class BaseAdmissionScanner implements HostKeyScanner
{
    public array $scans = [];

    public string $fingerprint = 'SHA256:pinned';

    public function scan(string $host, int $port, ?Node $via = null): HostKey
    {
        $this->scans[] = compact('host', 'port', 'via');

        return new HostKey('ssh-ed25519', 'PUBLICKEY', $this->fingerprint);
    }
}

final class BaseAdmissionHosts implements KnownHostsStore
{
    public array $hosts = [];

    public function path(): string
    {
        return '/tmp/orbit-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void
    {
        $this->hosts[] = "{$host}:{$port}:{$key->fingerprint}";
    }
}

function base_test_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 GATEWAY';
        }
    };
}

function base_provisionable_node(): Node
{
    return Node::query()->create(['name' => 'base-node', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.10', 'public_ssh_port' => 22, 'user' => 'root', 'wireguard_ip' => '10.44.0.2']);
}

function base_identity(): NodeProvisioningIdentity
{
    return new NodeProvisioningIdentity('root', 'orbit');
}

function base_node_converger(BaseAdmissionAgents $agents, ?NodeRoleFirewallManager $firewall = null, ?WireGuardPeerConverger $wireGuard = null, ?BaseAdmissionScanner $scanner = null, ?BaseAdmissionHosts $hosts = null, ?Closure $sleep = null): NativeNodeConverger
{
    if ($firewall === null) {
        $firewall = Mockery::mock(NodeRoleFirewallManager::class);
        $firewall->shouldReceive('convergeBase', 'trustWireGuardMembers')->andReturnNull();
    }
    $wireGuard ??= new class implements WireGuardPeerConverger
    {
        public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}
    };

    return new NativeNodeConverger($scanner ?? new BaseAdmissionScanner, $hosts ?? new BaseAdmissionHosts, base_test_keys(), new NodeBootstrapCommandFactory(base_test_keys()), $wireGuard, $firewall, $agents, $sleep);
}

it('pins the host and installs before native bootstrap using the selected login and managed identity', function (string $bootstrap, string $managed, bool $recoverable): void {
    $node = base_provisionable_node();
    $agents = new BaseAdmissionAgents;
    $hosts = new BaseAdmissionHosts;
    $recorded = false;
    $agents->answer = function (Node $target, SshConnection $connection, string $command) use (&$recorded): array {
        if ($command === 'agent.install') {
            expect($recorded)->toBeTrue();
            expect($target->fresh()->architecture)->toBe('x86_64');
        }

        return $command === 'agent.status' ? base_agent_status('x86_64') : [];
    };
    $firewall = Mockery::mock(NodeRoleFirewallManager::class);
    $firewall->shouldReceive('convergeBase')->once()->andReturnUsing(function () use ($agents): void {
        expect(array_column($agents->calls, 'command'))->toBe(['stage-architecture', 'agent.install', 'node.bootstrap', 'agent.status']);
    });
    $firewall->shouldReceive('trustWireGuardMembers')->once();
    $converger = base_node_converger($agents, $firewall, hosts: $hosts);
    $record = function (NodeObservation $observation) use ($node, &$recorded): void {
        $node->update(['architecture' => $observation->architecture]);
        $recorded = true;
    };
    $identity = new NodeProvisioningIdentity($bootstrap, $managed);
    if ($recoverable) {
        $converger->convergeRecoverably($node, $identity, 'SHA256:pinned', static function (): void {}, recordObservation: $record);
    } else {
        expect($converger->converge($node, $identity, 'SHA256:pinned', recordObservation: $record)->architecture)->toBe('x86_64');
    }
    expect($node->fresh()->ssh_host_fingerprint)->toBe('SHA256:pinned');
    expect($hosts->hosts)->toBe(['192.0.2.10:22:SHA256:pinned', '10.44.0.2:22:SHA256:pinned']);
    expect(array_column($agents->calls, 'command'))->toBe(['stage-architecture', 'agent.install', 'node.bootstrap', 'agent.status', 'agent.status']);
    expect(array_map(fn (array $call) => $call['connection']->user, $agents->calls))->toBe([$bootstrap, $bootstrap, $bootstrap, $managed, $managed]);
    expect($agents->calls[2]['input'])->toBe(new NodeBootstrapCommandFactory(base_test_keys())->input($node, $managed));
    expect($agents->calls[4]['connection']->host)->toBe('10.44.0.2');
    expect($agents->calls[0]['connection']->hostIdentity)->toBe('SHA256:pinned');
})->with([['root', 'orbit', false], ['nckrtl', 'nckrtl', false], ['orbit', 'orbit', true]]);

it('refuses unsupported platforms and untrusted host keys before agent work', function (?string $stored, ?string $expected, string $scanned, string $platform, string $code): void {
    $node = base_provisionable_node();
    $node->update(['platform' => $platform, 'ssh_host_fingerprint' => $stored]);
    $agents = new BaseAdmissionAgents;
    $scanner = new BaseAdmissionScanner;
    $scanner->fingerprint = $scanned;
    expect(fn () => base_node_converger($agents, scanner: $scanner)->converge($node, base_identity(), $expected))
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->errorCode)->toBe($code));
    expect($agents->calls)->toBe([]);
})->with([
    [null, null, 'SHA256:pinned', 'linux', 'node.ssh_host_fingerprint_required'],
    [null, 'SHA256:expected', 'SHA256:other', 'linux', 'node.ssh_host_key_mismatch'],
    ['SHA256:stored', null, 'SHA256:other', 'linux', 'node.ssh_host_key_changed'],
    [null, null, 'SHA256:pinned', 'windows', 'node.platform_unsupported'],
]);

it('stops before installation when architecture selection or its validation fails', function (bool $probeFails): void {
    $node = base_provisionable_node();
    $agents = new BaseAdmissionAgents;
    if ($probeFails) {
        $agents->answer = static fn () => throw new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, 'No architecture.');
    }
    expect(fn () => base_node_converger($agents)->converge($node, base_identity(), 'SHA256:pinned', recordObservation: static function (): void {
        throw new RuntimeException('The observation is refused.');
    }))->toThrow($probeFails ? NodeProvisioningException::class : RuntimeException::class);
    expect(array_column($agents->calls, 'command'))->toBe(['stage-architecture']);
})->with([false, true]);

it('refuses a Linux ARM machine before it records the observation or stages the agent', function (string $architecture): void {
    $node = base_provisionable_node();
    $agents = new BaseAdmissionAgents;
    $agents->architecture = $architecture;
    $recorded = false;

    expect(fn () => base_node_converger($agents)->converge($node, base_identity(), 'SHA256:pinned', recordObservation: static function () use (&$recorded): void {
        $recorded = true;
    }))->toThrow(function (NodeProvisioningException $e) use ($node, $architecture): void {
        expect($e->errorCode)->toBe('node.architecture_unsupported')
            ->and($e->status)->toBe(422)
            ->and($e->step)->toBe('machine-architecture')
            ->and($e->getMessage())->toBe("Node [{$node->name}] reports architecture [{$architecture}]. Linux Nodes must be [x86_64].");
    });
    expect($recorded)->toBeFalse()
        ->and(array_column($agents->calls, 'command'))->toBe(['stage-architecture'])
        ->and($node->fresh()->architecture)->toBeNull();
})->with(['aarch64', 'arm64']);

it('maps native failures and stops before networking', function (string $command, string $code, string $step, string $publicCode): void {
    $agents = new BaseAdmissionAgents;
    $agents->answer = static function (Node $node, SshConnection $connection, string $called) use ($command, $code): array {
        if ($called === $command) {
            throw new AgentCommandRefused($command, $code, 'Refused.', ['id' => 'ubuntu', 'codename' => 'unsupported']);
        }

        return [];
    };
    $firewall = Mockery::mock(NodeRoleFirewallManager::class);
    $firewall->shouldNotReceive('convergeBase', 'trustWireGuardMembers');
    expect(fn () => base_node_converger($agents, $firewall)->converge(base_provisionable_node(), new NodeProvisioningIdentity('root', 'nckrtl'), 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $e) use ($step, $publicCode, $code): void {
            expect($e->step)->toBe($step)->and($e->errorCode)->toBe($publicCode);
            if ($code === 'node.bootstrap_os_unsupported') {
                expect($e->getMessage())->toBe('Node operating system [ubuntu/unsupported] is not supported.');
            }
            if ($publicCode === 'node.orbit_ssh_failed') {
                expect($e->getMessage())->toContain('as nckrtl');
            }
        });
})->with([
    ['agent.install', 'agent.install_failed', 'agent-install', 'agent.install_failed'],
    ['node.bootstrap', 'node.bootstrap_failed', 'base-host', 'node.bootstrap_failed'],
    ['node.bootstrap', 'node.bootstrap_os_unsupported', 'base-host', 'node.bootstrap_failed'],
    ['agent.status', 'agent.identity_mismatch', 'orbit-ssh', 'node.orbit_ssh_failed'],
]);

it('verifies the installed architecture before networking', function (): void {
    $agents = new BaseAdmissionAgents;
    $agents->malformed = true;
    $agents->answer = static fn () => ['architecture' => 'invalid'];
    expect(fn () => base_node_converger($agents)->converge(base_provisionable_node(), base_identity(), 'SHA256:pinned'))
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->errorCode)->toBe('node.architecture_unavailable'));
});

it('retries only connection failures on the private read with bounded backoff and preserves the final result', function (bool $connectError, bool $recovers): void {
    $agents = new BaseAdmissionAgents;
    $privateCalls = 0;
    $result = new CommandResult(255, '', $connectError ? 'ssh: connect to host 10.44.0.2 port 22: timeout-final' : 'Permission denied (publickey).', 6, false);
    $agents->answer = static function (Node $node, SshConnection $connection, string $command) use (&$privateCalls, $recovers, $result): array {
        if ($connection->host === '10.44.0.2') {
            $privateCalls++;
            if (! $recovers || $privateCalls === 1) {
                throw new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'SSH failed.', result: $result);
            }
        }

        return base_agent_status('x86_64');
    };
    $sleeps = [];
    $converger = base_node_converger($agents, sleep: function (int $delay) use (&$sleeps): void {
        $sleeps[] = $delay;
    });
    $node = base_provisionable_node();
    if ($recovers) {
        $converger->converge($node, base_identity(), 'SHA256:pinned');
    } else {
        expect(fn () => $converger->converge($node, base_identity(), 'SHA256:pinned'))->toThrow(function (NodeProvisioningException $e) use ($result): void {
            expect($e->errorCode)->toBe('vpn.peer_ssh_failed')->and($e->result)->toBe($result);
        });
    }
    expect($sleeps)->toBe($connectError ? ($recovers ? [1_000_000] : [1_000_000, 2_000_000]) : []);
    expect($privateCalls)->toBe(count($sleeps) + 1);
})->with([[true, true], [true, false], [false, false]]);

it('keeps peer publication recovery and skips it only for active roles', function (string $roleState, bool $failPrivate): void {
    $node = base_provisionable_node();
    if ($roleState !== 'none') {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => $roleState]);
    }
    $agents = new BaseAdmissionAgents;
    $agents->answer = static function (Node $node, SshConnection $connection, string $command) use ($failPrivate): array {
        if ($connection->host === '10.44.0.2' && $command === 'agent.status' && $failPrivate) {
            throw new AgentCommandRefused($command, 'agent.identity_mismatch', 'Failed.');
        }

        return base_agent_status('x86_64');
    };
    $events = [];
    $wireGuard = new class($events) implements RecoverableWireGuardPeerConverger, WireGuardPeerConverger
    {
        public function __construct(private array &$events) {}

        public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void
        {
            throw new LogicException('Must use recovery.');
        }

        public function convergeRecoverably(Node $node, SshConnection $connection, Closure $completion, bool $rolelessOperator = false): void
        {
            $this->events[] = 'publish';
            try {
                $completion(function (SshConnection $verified): void {
                    $this->events[] = 'finalize:'.$verified->host;
                });
                $this->events[] = 'commit';
            } catch (Throwable $e) {
                $this->events[] = 'rollback';
                throw $e;
            }
        }
    };
    $run = function () use ($agents, $wireGuard, $node, &$events): void {
        base_node_converger($agents, wireGuard: $wireGuard)->convergeRecoverably($node, base_identity(), 'SHA256:pinned', function () use (&$events): void {
            $events[] = 'complete';
        });
    };
    if ($failPrivate) {
        expect($run)->toThrow(NodeProvisioningException::class);
    } else {
        $run();
    }
    expect($events)->toBe($roleState === 'active' ? ['complete'] : ($failPrivate ? ['publish', 'rollback'] : ['publish', 'finalize:10.44.0.2', 'complete', 'commit']));
})->with([['none', false], ['failed', false], ['active', false], ['none', true]]);

it('uses the jump node for public admission and direct WireGuard for managed reads', function (bool $activeRole, bool $missingJumpAddress): void {
    $jump = Node::query()->create(['name' => 'jump', 'public_ssh_host' => '192.0.2.7', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'nckrtl', 'wireguard_ip' => $missingJumpAddress ? null : '10.44.0.7']);
    $node = base_provisionable_node();
    $node->update(['ssh_jump_node_id' => $jump->id]);
    if ($activeRole) {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }
    $agents = new BaseAdmissionAgents;
    $scanner = new BaseAdmissionScanner;
    $run = fn () => base_node_converger($agents, scanner: $scanner)->converge($node, base_identity(), 'SHA256:pinned');
    if ($missingJumpAddress) {
        expect($run)->toThrow(NodeProvisioningException::class);
        expect($scanner->scans)->toBe([])->and($agents->calls)->toBe([]);

        return;
    }
    $run();
    expect($scanner->scans[0]['via']?->id)->toBe($activeRole ? null : $jump->id);
    foreach ($agents->calls as $index => $call) {
        $public = ! $activeRole && $index < 4;
        expect($call['connection']->host)->toBe($public ? '192.0.2.10' : '10.44.0.2');
        expect($call['connection']->proxyJump?->host)->toBe($public ? '10.44.0.7' : null);
        expect($call['connection']->hostIdentity)->toBe('SHA256:pinned');
    }
})->with([[false, false], [true, false], [false, true]]);

it('translates base firewall failures before peer convergence', function (): void {
    $firewall = Mockery::mock(NodeRoleFirewallManager::class);
    $firewall->shouldReceive('convergeBase')->once()->andThrow(new FirewallOperationException('base-firewall', 'firewall.failed', 'Failed.'));
    $firewall->shouldNotReceive('trustWireGuardMembers');
    expect(fn () => base_node_converger(new BaseAdmissionAgents, $firewall)->converge(base_provisionable_node(), base_identity(), 'SHA256:pinned'))
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->errorCode)->toBe('firewall.failed'));
});

/** @return array<string, string> */
function base_agent_status(string $architecture): array
{
    return ['version' => '0.5.0', 'sha256' => str_repeat('a', 64), 'platform' => 'linux', 'architecture' => $architecture, 'observer' => 'active'];
}
