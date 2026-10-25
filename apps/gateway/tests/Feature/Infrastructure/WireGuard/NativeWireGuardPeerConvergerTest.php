<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\FakeAgentCommandRunner;

const PEER_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

final class PeerAdmissionAgents implements AdmissionAgentRunner
{
    /** @var list<SshConnection> */
    public array $connections = [];

    public function __construct(public FakeAgentCommandRunner $runner) {}

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        throw new LogicException('Peer convergence does not probe architecture.');
    }

    public function installOver(Node $node, SshConnection $connection): bool
    {
        throw new LogicException('Admission already installed command mode.');
    }

    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->connections[] = $connection;

        return $this->runner->run($node, $command, $input, $deadlineSeconds);
    }
}

/** @return array{NativeWireGuardPeerConverger, Node, SshConnection, PeerAdmissionAgents, FakeAgentCommandRunner} */
function native_peer_fixture(): array
{
    $home = sys_get_temp_dir().'/orbit-native-peer-'.bin2hex(random_bytes(8));
    test()->peerFixtureHome = $home;
    mkdir($home.'/wireguard', 0700, true);
    file_put_contents($home.'/wireguard/private.key', str_repeat('S', 43).'=');
    file_put_contents($home.'/wireguard/public.key', str_repeat('P', 43).'=');
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'wireguard_public_key' => str_repeat('P', 43).'=']);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $peer = Node::query()->create(['name' => 'app-dev', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'wireguard_public_key' => PEER_KEY, 'tld' => 'custom.internal']);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(subnet: '10.44.0.0/24', endpoint: '192.0.2.1:51820', dnsServer: '10.44.0.53');
    $configuration = new VpnConfigurationRepository($settings, $home);
    $hub = (new FakeAgentCommandRunner)->answer('wireguard.project-hub', []);
    $agents = new PeerAdmissionAgents((new FakeAgentCommandRunner)
        ->answer('wireguard.prepare-peer-key', ['public_key' => PEER_KEY])
        ->answer('wireguard.publish-peer', ['public_key' => PEER_KEY])
        ->answer('wireguard.commit-peer', [])
        ->answer('wireguard.rollback-peer', []));
    $converger = new NativeWireGuardPeerConverger($configuration, new NativeGatewayPeerProjectionManager(
        $configuration, new WireGuardServerConfigRenderer, new ProtectedFileWriter, $hub, $home,
    ), $agents);

    return [$converger, $peer, new SshConnection('192.0.2.2', 'orbit', 22, '/tmp/key', '/tmp/known-hosts'), $agents, $hub];
}

afterEach(function (): void {
    if (isset($this->peerFixtureHome)) {
        new Filesystem()->deleteDirectory($this->peerFixtureHome);
    }
});

it('sends peer intent through the bootstrap connection after projecting the hub', function (bool $operator): void {
    [$converger, $peer, $connection, $agents, $hub] = native_peer_fixture();

    $converger->converge($peer, $connection, $operator);

    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key', 'wireguard.publish-peer']);
    expect($agents->connections)->toBe([$connection, $connection]);
    expect($agents->runner->calls[0]['input'])->toBe(['existing' => false]);
    expect($agents->runner->calls[1]['input'])->toBe([
        'server_public_key' => str_repeat('P', 43).'=', 'peer_public_key' => PEER_KEY,
        'endpoint' => '192.0.2.1:51820', 'address' => '10.44.0.2/24', 'subnet' => '10.44.0.0/24',
        'dns_server' => '10.44.0.53', 'domains' => $operator ? ['orbit'] : ['custom.internal', 'orbit'],
        'operator_dns' => $operator ? '10.44.0.1' : null, 'retain' => false,
    ]);
    expect($hub->commands())->toBe(['wireguard.project-hub']);
    expect($peer->fresh()->wireguard_public_key)->toBe(PEER_KEY);
    expect(json_encode($agents->runner->calls, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('S', 43));
})->with([false, true]);

it('rejects invalid names before key or hub mutations', function (): void {
    [$converger, $peer, $connection, $agents, $hub] = native_peer_fixture();
    Node::query()->whereKey($peer->id)->toBase()->update(['tld' => 'bad;name']);

    expect(fn () => $converger->converge($peer->fresh(), $connection))->toThrow(NodeProvisioningException::class);
    expect($agents->runner->calls)->toBe([]);
    expect($hub->calls)->toBe([]);
});

it('rejects malformed key results before changing desired state or projecting the hub', function (array $result): void {
    [$converger, $peer, $connection, $agents, $hub] = native_peer_fixture();
    $agents->runner->answer('wireguard.prepare-peer-key', $result);

    expect(fn () => $converger->converge($peer, $connection))->toThrow(function (NodeProvisioningException $error): void {
        expect($error->errorCode)->toBe('vpn.peer_key_failed');
    });
    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key']);
    expect($hub->calls)->toBe([]);
    expect($peer->fresh()->wireguard_public_key)->toBe(PEER_KEY);
})->with([
    'absent' => [[]],
    'wrong type' => [['public_key' => 12]],
    'invalid key' => [['public_key' => 'not-a-key']],
]);

it('requires the persisted key before recoverable publication', function (bool $missing): void {
    [$converger, $peer, $connection, $agents, $hub] = native_peer_fixture();
    if ($missing) {
        $agents->runner->refuse('wireguard.prepare-peer-key', 'wireguard.peer_key_missing');
    } else {
        $agents->runner->answer('wireguard.prepare-peer-key', ['public_key' => str_repeat('B', 43).'=']);
    }

    expect(fn () => $converger->convergeRecoverably($peer, $connection, static function (): void {
        test()->fail('Continuation must not run.');
    }))->toThrow(function (NodeProvisioningException $error) use ($missing): void {
        expect($error->errorCode)->toBe($missing ? 'vpn.peer_key_missing' : 'vpn.peer_key_mismatch');
    });
    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key']);
    expect($hub->calls)->toBe([]);
    expect($peer->fresh()->wireguard_public_key)->toBe(PEER_KEY);
})->with([false, true]);

it('commits successful admission over the verified tunnel connection', function (): void {
    [$converger, $peer, $connection, $agents] = native_peer_fixture();
    $tunnel = new SshConnection('10.44.0.2', 'orbit', 22, '/tmp/key', '/tmp/known-hosts');

    $converger->convergeRecoverably($peer, $connection, static function (Closure $finalize) use ($tunnel): void {
        $finalize($tunnel);
    });

    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key', 'wireguard.publish-peer', 'wireguard.commit-peer']);
    expect($agents->runner->calls[0]['input'])->toBe(['existing' => true]);
    expect($agents->runner->calls[1]['input']['retain'])->toBeTrue();
    expect($agents->runner->calls[2]['input'])->toBe(['public_key' => PEER_KEY]);
    expect($agents->connections)->toBe([$connection, $connection, $tunnel]);
});

it('rolls back a failed continuation or commit over its last verified connection', function (bool $commitFails): void {
    [$converger, $peer, $connection, $agents] = native_peer_fixture();
    $tunnel = new SshConnection('10.44.0.2', 'orbit', 22, '/tmp/key', '/tmp/known-hosts');
    if ($commitFails) {
        $agents->runner->refuse('wireguard.commit-peer', 'wireguard.peer_commit_failed');
    }

    expect(fn () => $converger->convergeRecoverably($peer, $connection, static function (Closure $finalize) use ($tunnel, $commitFails): void {
        $finalize($tunnel);
        if (! $commitFails) {
            throw new RuntimeException('continuation failed');
        }
    }))->toThrow($commitFails ? NodeProvisioningException::class : RuntimeException::class);
    expect(end($agents->connections))->toBe($tunnel);
    expect($agents->runner->commands())->toBe($commitFails
        ? ['wireguard.prepare-peer-key', 'wireguard.publish-peer', 'wireguard.commit-peer', 'wireguard.rollback-peer']
        : ['wireguard.prepare-peer-key', 'wireguard.publish-peer', 'wireguard.rollback-peer']);
})->with([false, true]);

it('reports a failed rollback rather than claiming recovery', function (): void {
    [$converger, $peer, $connection, $agents] = native_peer_fixture();
    $agents->runner->refuse('wireguard.rollback-peer', 'wireguard.peer_rollback_failed');

    expect(fn () => $converger->convergeRecoverably($peer, $connection, static function (): never {
        throw new RuntimeException('continuation failed');
    }))->toThrow(function (NodeProvisioningException $error): void {
        expect($error->errorCode)->toBe('vpn.peer_rollback_failed');
    });
});

it('maps native publication failures without running the continuation or a second rollback', function (string $native, string $public): void {
    [$converger, $peer, $connection, $agents] = native_peer_fixture();
    $agents->runner->refuse('wireguard.publish-peer', $native);

    expect(fn () => $converger->convergeRecoverably($peer, $connection, static function (): void {
        test()->fail('Continuation must not run.');
    }))->toThrow(function (NodeProvisioningException $error) use ($public): void {
        expect($error->errorCode)->toBe($public);
    });
    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key', 'wireguard.publish-peer']);
})->with([
    ['wireguard.peer_recovery_pending', 'vpn.peer_recovery_pending'],
    ['wireguard.peer_state_unsupported', 'vpn.peer_state_unsupported'],
    ['wireguard.peer_dns_uplink_missing', 'vpn.peer_dns_uplink_missing'],
    ['wireguard.peer_config_failed', 'vpn.peer_config_failed'],
    ['wireguard.peer_rollback_failed', 'vpn.peer_rollback_failed'],
]);

it('does not retry or infer failure to mutate after a lost publication result', function (): void {
    [$converger, $peer, $connection, $agents] = native_peer_fixture();
    $agents->runner->answer('wireguard.publish-peer', static fn (): never => throw new AgentRunnerFailure(
        'wireguard.publish-peer', AgentRunnerFailure::Transport, 'connection lost',
    ));

    expect(fn () => $converger->convergeRecoverably($peer, $connection, static function (): void {
        test()->fail('Continuation must not run.');
    }))->toThrow(NodeProvisioningException::class);
    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key', 'wireguard.publish-peer']);
});

it('does not publish the peer if hub projection fails', function (): void {
    [$converger, $peer, $connection, $agents, $hub] = native_peer_fixture();
    $hub->refuse('wireguard.project-hub', 'wireguard.hub_config_invalid');

    expect(fn () => $converger->converge($peer, $connection))->toThrow(NodeProvisioningException::class);
    expect($agents->runner->commands())->toBe(['wireguard.prepare-peer-key']);
});
