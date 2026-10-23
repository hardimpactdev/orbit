<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\WireGuard\NativeWireGuardPeerDnsRepairer;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('repairs the peer with the VPN DNS server, its key, and the policy domains', function (): void {
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal');
    $harness->agents->answer('wireguard.repair-peer-dns', []);

    try {
        $harness->repairer->repair($harness->peer);

        expect($harness->agents->calls)->toBe([[
            'node' => 'peer-dns-repair',
            'command' => 'wireguard.repair-peer-dns',
            'input' => [
                'dns_server' => '10.43.0.53',
                'public_key' => str_repeat(string: 'A', times: 43).'=',
                'domains' => ['custom.internal', 'orbit', 'test'],
            ],
        ]]);
    } finally {
        $harness->cleanup();
    }
});

it('leaves a Gateway with AppDev and a separate VPN holder untouched', function (bool $tldConvergence): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal');
    $harness->peer->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    try {
        $tldConvergence
            ? new RemoteAppDevTldRouteManager($harness->repairer)->converge($harness->peer)
            : $harness->repairer->repair($harness->peer);
        $exception = null;
    } catch (NodeProvisioningException|RuntimeConvergenceException $failure) {
        $exception = $failure;
    } finally {
        $harness->cleanup();
    }

    expect($exception?->errorCode)->toBe($tldConvergence ? 'app-dev.tld_route_failed' : 'vpn.peer_dns_unmanaged')
        ->and($harness->agents->calls)->toBe([]);
})->with(['native repair' => false, 'AppDev TLD convergence' => true]);

it('maps each repair refusal to its step and error code', function (string $refusal, string $step, string $errorCode): void {
    $harness = wireguard_peer_dns_repair_harness();
    $harness->agents->refuse('wireguard.repair-peer-dns', $refusal);

    try {
        expect(fn () => $harness->repairer->repair($harness->peer))
            ->toThrow(function (NodeProvisioningException $exception) use ($step, $errorCode, $refusal): void {
                expect($exception->step)->toBe($step)
                    ->and($exception->errorCode)->toBe($errorCode)
                    ->and($exception->getMessage())->toContain('peer-dns-repair')
                    ->and($exception->result?->stdout)->toContain($refusal);
            });
    } finally {
        $harness->cleanup();
    }
})->with([
    'busy' => ['wireguard.peer_dns_busy', 'wireguard-peer-lock', 'vpn.peer_dns_busy'],
    'pending recovery' => ['wireguard.peer_recovery_pending', 'wireguard-peer-transaction', 'vpn.peer_recovery_pending'],
    'no uplink' => ['wireguard.peer_dns_uplink_missing', 'wireguard-peer-dns-uplink', 'vpn.peer_dns_uplink_missing'],
    'unsupported state' => ['wireguard.peer_dns_state_unsupported', 'wireguard-peer-state', 'vpn.peer_dns_state_unsupported'],
    'invalid candidate' => ['wireguard.peer_dns_candidate_invalid', 'wireguard-peer-dns-candidate', 'vpn.peer_dns_candidate_invalid'],
    'apply' => ['wireguard.peer_dns_apply_failed', 'wireguard-peer-dns-apply', 'vpn.peer_dns_apply_failed'],
    'recovery' => ['wireguard.peer_dns_recovery_failed', 'wireguard-peer-dns-recovery', 'vpn.peer_dns_recovery_failed'],
    'other' => ['wireguard.peer_dns_repair_failed', 'wireguard-peer-dns-repair', 'vpn.peer_dns_repair_failed'],
]);

it('reports a repair without an agent result as failed', function (): void {
    $harness = wireguard_peer_dns_repair_harness();
    $harness->agents->answer('wireguard.repair-peer-dns', static fn (): array => throw new AgentRunnerFailure('wireguard.repair-peer-dns', AgentRunnerFailure::Transport, 'The connection dropped.'));

    try {
        expect(fn () => $harness->repairer->repair($harness->peer))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('wireguard-peer-dns-repair')
                    ->and($exception->errorCode)->toBe('vpn.peer_dns_repair_failed');
            });
    } finally {
        $harness->cleanup();
    }
});

function wireguard_peer_dns_repair_harness(?string $tld = null): object
{
    $root = sys_get_temp_dir().'/orbit-peer-dns-repair-'.Str::uuid();
    mkdir("{$root}/wireguard", 0o700, true);
    file_put_contents("{$root}/wireguard/private.key", str_repeat(string: 'S', times: 43).'=');
    file_put_contents("{$root}/wireguard/public.key", str_repeat(string: 'P', times: 43).'=');
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(subnet: '10.43.0.0/24', endpoint: '192.0.2.1:51820', dnsServer: '10.43.0.53');
    $gateway = Node::query()->create([
        'name' => 'gateway-dns-repair',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.43.0.1',
        'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $peer = Node::query()->create([
        'name' => 'peer-dns-repair',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.7',
        'user' => 'orbit',
        'wireguard_ip' => '10.43.0.7',
        'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
        'tld' => $tld,
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $peer->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $agents = new FakeAgentCommandRunner;

    return new class($root, $peer, $agents, new NativeWireGuardPeerDnsRepairer(new VpnConfigurationRepository($settings, $root), $agents))
    {
        public function __construct(
            private readonly string $root,
            public readonly Node $peer,
            public readonly FakeAgentCommandRunner $agents,
            public readonly NativeWireGuardPeerDnsRepairer $repairer,
        ) {}

        public function cleanup(): void
        {
            new Filesystem()->deleteDirectory($this->root);
        }
    };
}
