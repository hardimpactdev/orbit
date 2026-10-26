<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('compares the active interface and the digests of the rendered server and DNS projections', function (): void {
    $harness = gateway_vpn_inspector();
    $harness->answer(true, $harness->serverConfig(), $harness->dnsConfig());

    try {
        $state = $harness->inspector->inspect($harness->role);

        expect([$state->interfaceActive, $state->serverConfigMatches, $state->dnsConfigMatches])
            ->toBe([true, true, true])
            ->and($harness->agents->calls)
            ->toBe([['node' => 'gateway-vpn-inspector', 'command' => 'wireguard.inspect-hub', 'input' => []]])
            ->and($harness->serverConfig())
            ->toContain('PrivateKey = SERVER_PRIVATE')
            ->and($harness->dnsConfig())
            ->toStartWith('# Managed by Orbit.');
    } finally {
        $harness->cleanup();
    }
});

it('inspects the explicitly selected VPN role when a lower fleet assignment exists', function (): void {
    $harness = gateway_vpn_inspector();
    $harness->answer(true, '', '');
    $selected = Node::query()->create([
        'name' => 'selected-gateway-vpn-inspector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.2',
        'wireguard_public_key' => 'SELECTED_PUBLIC',
    ]);
    $selectedRole = $selected->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);

    try {
        $harness->inspector->inspect($selectedRole);

        expect(array_column($harness->agents->calls, 'node'))->toBe(['selected-gateway-vpn-inspector']);
    } finally {
        $harness->cleanup();
    }
});

it('renders VPN peers in stable persisted order', function (): void {
    $harness = gateway_vpn_inspector();
    $firstPeer = Node::query()->create([
        'name' => 'peer-z',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9',
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.9',
        'wireguard_public_key' => 'PEER_Z_PUBLIC',
    ]);
    $secondPeer = Node::query()->create([
        'name' => 'peer-a',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.8',
        'wireguard_public_key' => 'PEER_A_PUBLIC',
    ]);
    $harness->answer(true, implode(PHP_EOL, [
        '[Interface]',
        'Address = 10.44.0.1/24',
        'ListenPort = 51820',
        'PrivateKey = SERVER_PRIVATE',
        '',
        '[Peer]',
        "# {$firstPeer->name}",
        'PublicKey = PEER_Z_PUBLIC',
        'AllowedIPs = 10.44.0.9/32',
        '',
        '[Peer]',
        "# {$secondPeer->name}",
        'PublicKey = PEER_A_PUBLIC',
        'AllowedIPs = 10.44.0.8/32',
        '',
    ]), $harness->dnsConfig());

    try {
        DB::statement('PRAGMA reverse_unordered_selects = ON');

        expect($harness->inspector->inspect($harness->role)->serverConfigMatches)->toBeTrue();
    } finally {
        DB::statement('PRAGMA reverse_unordered_selects = OFF');
        $harness->cleanup();
    }
});

it('returns independent mismatch observations, a missing file included', function (bool $active, ?string $server, ?string $dns, array $expected): void {
    $harness = gateway_vpn_inspector();
    $harness->answer($active, $server ?? $harness->serverConfig(), $dns ?? $harness->dnsConfig(), missing: $server === 'missing' ? 'config_sha256' : null);

    try {
        $state = $harness->inspector->inspect($harness->role);

        expect([$state->interfaceActive, $state->serverConfigMatches, $state->dnsConfigMatches])->toBe($expected);
    } finally {
        $harness->cleanup();
    }
})->with([
    'inactive interface' => [false, null, null, [false, true, true]],
    'server mismatch' => [true, 'other', null, [true, false, true]],
    'DNS mismatch' => [true, null, "other\n", [true, true, false]],
    'missing server file' => [true, 'missing', null, [true, false, true]],
]);

it('fails closed for a refusal, a lost result, and malformed output', function (string $case): void {
    $harness = gateway_vpn_inspector();
    match ($case) {
        'refusal' => $harness->agents->refuse('wireguard.inspect-hub', 'wireguard.hub_unreadable'),
        'transport' => $harness->agents->answer('wireguard.inspect-hub', static fn (): array => throw new AgentRunnerFailure('wireguard.inspect-hub', AgentRunnerFailure::Transport, 'secret')),
        'malformed active' => $harness->agents->answerMalformed('wireguard.inspect-hub', ['active' => 'yes', 'config_sha256' => null, 'dns_records_sha256' => null]),
        'missing digest' => $harness->agents->answerMalformed('wireguard.inspect-hub', ['active' => true, 'config_sha256' => null]),
        'malformed digest' => $harness->agents->answerMalformed('wireguard.inspect-hub', ['active' => true, 'config_sha256' => 7, 'dns_records_sha256' => null]),
    };

    try {
        expect(fn () => $harness->inspector->inspect($harness->role))->toThrow(DoctorInspectionException::class, '');
    } finally {
        $harness->cleanup();
    }
})->with(['refusal', 'transport', 'malformed active', 'missing digest', 'malformed digest']);

function gateway_vpn_inspector(): object
{
    $orbitHome = sys_get_temp_dir().'/orbit-doctor-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');

    $gateway = Node::query()->create([
        'name' => 'gateway-vpn-inspector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => 'SERVER_PUBLIC',
    ]);
    $role = $gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure('10.44.0.0/24');
    $configuration = new VpnConfigurationRepository($settings, $orbitHome);
    $dns = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class));
    $agents = new FakeAgentCommandRunner;
    $inspector = new NativeGatewayVpnStateInspector($agents, $configuration, new WireGuardServerConfigRenderer, $dns, new CommandDeadline);

    return new class($inspector, $agents, $role, $orbitHome, $configuration, $dns)
    {
        public function __construct(
            public readonly NativeGatewayVpnStateInspector $inspector,
            public readonly FakeAgentCommandRunner $agents,
            public readonly NodeRole $role,
            private readonly string $orbitHome,
            private readonly VpnConfigurationRepository $configuration,
            private readonly DevelopmentDnsConfigRenderer $dns,
        ) {}

        public function serverConfig(): string
        {
            return new WireGuardServerConfigRenderer()->render(
                $this->configuration->forPeer($this->role->node),
                Node::query()->whereNotNull('wireguard_public_key')->get(),
            );
        }

        public function dnsConfig(): string
        {
            return $this->dns->render();
        }

        /** The agent reports the digests of these file contents; `missing` reports that file absent. */
        public function answer(bool $active, string $server, string $dns, ?string $missing = null): void
        {
            $state = ['active' => $active, 'config_sha256' => hash('sha256', $server), 'dns_records_sha256' => hash('sha256', $dns)];
            if ($missing !== null) {
                $state[$missing] = null;
            }
            $this->agents->answer('wireguard.inspect-hub', $state);
        }

        public function cleanup(): void
        {
            new Filesystem()->deleteDirectory($this->orbitHome);
        }
    };
}
