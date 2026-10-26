<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('removes and restores only the selected peer under the projection lock', function (): void {
    $harness = gateway_peer_projection_harness(split: false);
    $removed = Node::query()->create([
        'name' => 'removed-peer',
        'public_ssh_host' => '94.237.40.76',
        'wireguard_ip' => '10.44.0.4',
        'wireguard_public_key' => str_repeat(string: 'B', times: 43).'=',
    ]);
    $locked = [];
    $harness->agents->answer('wireguard.project-hub', function () use ($harness, &$locked): array {
        $lock = fopen($harness->orbitHome.'/locks/wireguard-server.lock', 'c+');
        $acquired = flock($lock, LOCK_EX | LOCK_NB);
        $locked[] = ! $acquired;
        if ($acquired) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        return [];
    });

    try {
        $harness->manager->remove($removed);
        $afterRemoval = $harness->agents->calls[0]['input']['config'];
        $harness->manager->restore($removed);

        expect($afterRemoval)
            ->not->toContain('# removed-peer', 'AllowedIPs = 10.44.0.4/32')
            ->toContain('# services', 'AllowedIPs = 10.44.0.3/32')
            ->and($harness->agents->calls[1]['input']['config'])
            ->toContain('# removed-peer', 'AllowedIPs = 10.44.0.4/32', '# services')
            ->and(file_get_contents($harness->orbitHome.'/generated/wireguard/orbit.conf'))
            ->toBe($harness->agents->calls[1]['input']['config'])
            ->and($locked)
            ->toBe([true, true]);
    } finally {
        $harness->cleanup();
    }
});

it('projects the hub onto the vpn Node, split from the Gateway or shared with it', function (bool $split): void {
    $harness = gateway_peer_projection_harness(split: $split);
    $harness->agents->answer('wireguard.project-hub', []);

    try {
        $harness->manager->converge($harness->peer);

        expect($harness->agents->calls)
            ->toHaveCount(1)
            ->and($harness->agents->calls[0]['node'])
            ->toBe($harness->vpn->name)
            ->and($harness->agents->calls[0]['command'])
            ->toBe('wireguard.project-hub')
            ->and($harness->agents->calls[0]['input']['config'])
            ->toBe((string) file_get_contents($harness->orbitHome.'/generated/wireguard/orbit.conf'))
            ->toContain(
                'PrivateKey = '.$harness->hubPrivateKey,
                'Address = 10.44.0.1/24',
                '# services',
                'AllowedIPs = 10.44.0.3/32',
            );
    } finally {
        $harness->cleanup();
    }
})->with(['split' => true, 'shared' => false]);

it('maps each hub refusal to its step and error code', function (string $refusal, string $step, string $errorCode): void {
    $harness = gateway_peer_projection_harness(split: true);
    $harness->agents->refuse('wireguard.project-hub', $refusal);

    try {
        expect(fn () => $harness->manager->converge($harness->peer))
            ->toThrow(function (NodeProvisioningException $exception) use ($harness, $step, $errorCode, $refusal): void {
                expect($exception->step)->toBe($step)
                    ->and($exception->errorCode)->toBe($errorCode)
                    ->and($exception->getMessage())->toContain($harness->vpn->name)
                    ->and($exception->result?->stdout)->toContain($refusal);
            });
    } finally {
        $harness->cleanup();
    }
})->with([
    'install' => ['wireguard.hub_install_failed', 'wireguard-server-install', 'vpn.server_config_install_failed'],
    'invalid' => ['wireguard.hub_config_invalid', 'wireguard-server-validate', 'vpn.server_config_invalid'],
    'start' => ['wireguard.hub_start_failed', 'wireguard-server-restart', 'vpn.server_start_failed'],
]);

it('fails the hub start step when no agent result arrives', function (): void {
    $harness = gateway_peer_projection_harness(split: true);
    $harness->agents->answer('wireguard.project-hub', static fn (): array => throw new AgentRunnerFailure('wireguard.project-hub', AgentRunnerFailure::Transport, 'The connection dropped.'));

    try {
        expect(fn () => $harness->manager->converge($harness->peer))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('wireguard-server-restart')
                    ->and($exception->errorCode)->toBe('vpn.server_start_failed');
            });
    } finally {
        $harness->cleanup();
    }
});

function gateway_peer_projection_harness(bool $split): object
{
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-projection-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    $hubPrivateKey = str_repeat(string: 'S', times: 43).'=';
    file_put_contents($orbitHome.'/wireguard/private.key', $hubPrivateKey);
    file_put_contents($orbitHome.'/wireguard/public.key', str_repeat(string: 'P', times: 43).'=');

    $vpn = Node::query()->create([
        'name' => $split ? 'vpn' : 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '85.9.218.89',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
    ]);
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $gateway = $split ? Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '85.9.218.90',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
        'wireguard_public_key' => str_repeat(string: 'G', times: 43).'=',
    ]) : $vpn;
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $peer = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '94.237.40.75',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
        'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
    ]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(subnet: '10.44.0.0/24', endpoint: '85.9.218.89:51820', dnsServer: '10.44.0.1');
    $agents = new FakeAgentCommandRunner;
    $manager = new NativeGatewayPeerProjectionManager(
        configuration: new VpnConfigurationRepository($settings, $orbitHome),
        serverRenderer: new WireGuardServerConfigRenderer,
        files: new ProtectedFileWriter,
        agents: $agents,
        orbitHome: $orbitHome,
    );

    return new class($orbitHome, $hubPrivateKey, $vpn, $peer, $manager, $agents)
    {
        public function __construct(
            public readonly string $orbitHome,
            public readonly string $hubPrivateKey,
            public readonly Node $vpn,
            public readonly Node $peer,
            public readonly NativeGatewayPeerProjectionManager $manager,
            public readonly FakeAgentCommandRunner $agents,
        ) {}

        public function cleanup(): void
        {
            new Filesystem()->deleteDirectory($this->orbitHome);
        }
    };
}
