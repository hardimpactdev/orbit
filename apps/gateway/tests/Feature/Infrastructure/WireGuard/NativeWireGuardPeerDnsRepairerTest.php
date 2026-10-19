<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\NativeWireGuardPeerDnsRepairer;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\HostBinary;
use Tests\Support\TestToolchain;

it('repairs live and persisted DNS repeatedly without restarting the tunnel', function (): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $first = $harness->repair();
        $second = $harness->repair();

        expect($first['exception'])
            ->toBeNull($first['exception']?->result?->stderr ?? '')
            ->and($second['exception'])
            ->toBeNull($second['exception']?->result?->stderr ?? '')
            ->and($second['live'])
            ->toBe($first['live'])
            ->toContain(
                'PrivateKey = stable-private-key',
                'Address = 10.43.0.7/24',
                'PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit',
                'AllowedIPs = 10.43.0.0/24',
            )
            ->not->toContain('PreDown =')
            ->and($second['dns'])
            ->toBe("orbit\n10.43.0.53\norbit\n")
            ->and($second['resolver']['orbit'])
            ->toBe([
                'dns' => ['10.43.0.53'],
                'domains' => ['~orbit'],
                'unrelated' => $before['resolver']['orbit']['unrelated'],
            ])
            ->and($second['resolver']['eth0'])
            ->toBe($before['resolver']['eth0'])
            ->and($second['private_key'])
            ->toBe('stable-private-key-material')
            ->and($second['artifacts'])
            ->toBe([])
            ->and($second['commands'])
            ->toContain(
                'systemctl is-active --quiet wg-quick@orbit',
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~orbit',
            )
            ->not->toContain(
                'systemctl restart wg-quick@orbit',
                'systemctl stop wg-quick@orbit',
                'systemctl start wg-quick@orbit',
            )
            ->and($second['connections'])
            ->toHaveCount(2)
            ->and($second['connections'][0])
            ->toMatchArray([
                'host' => '10.43.0.7',
                'user' => 'orbit',
                'port' => 22,
                'identityFile' => '/gateway/ssh/id_ed25519',
                'knownHostsFile' => '/gateway/ssh/known_hosts',
            ]);
    } finally {
        $harness->cleanup();
    }
});

it('leaves a Gateway with AppDev and a separate VPN holder untouched', function (bool $tldConvergence): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal');
    try {
        $peer = Node::query()->where('name', 'peer-dns-repair')->firstOrFail();
        $peer->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $before = $harness->state();
        $result = $harness->repair($tldConvergence);
        expect($result['exception'])->not->toBeNull()
            ->and($result['exception']->errorCode)->toBe($tldConvergence ? 'app-dev.tld_route_failed' : 'vpn.peer_dns_unmanaged')
            ->and($result['resolver'])->toBe($before['resolver'])
            ->and($result['live'])->toBe($before['live'])
            ->and($result['dns'])->toBe($before['dns'])
            ->and($result['connections'])->toBe([]);
    } finally {
        $harness->cleanup();
    }
})->with(['native repair' => false, 'AppDev TLD convergence' => true]);

it('repairs a hookless managed configuration without adding a PreDown hook', function (): void {
    $harness = wireguard_peer_dns_repair_harness(hooklessResolverHooks: true);

    try {
        $before = $harness->state();
        $result = $harness->repair();

        expect($result['exception'])
            ->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and($result['live'])
            ->toContain('PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit')
            ->not->toContain('PreDown =')
            ->and($result['dns'])
            ->toBe("orbit\n10.43.0.53\norbit\n")
            ->and($result['resolver']['orbit'])
            ->toBe([
                'dns' => ['10.43.0.53'],
                'domains' => ['~orbit'],
                'unrelated' => $before['resolver']['orbit']['unrelated'],
            ])
            ->and($result['artifacts'])
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('migrates an old hookless underlay override and restores provider DNS', function (): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal', previousUnderlay: true);
    try {
        $before = $harness->state();
        $result = $harness->repair();
        expect($result['exception'])->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and($result['resolver']['eth0']['unrelated'])->toBe(array_replace($before['resolver']['eth0']['unrelated'], ['default-route' => 'yes']))
            ->and($result['live'])->toContain('PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~custom.internal \\~orbit')
            ->and($result['dns'])->toBe("orbit\n10.43.0.53\ncustom.internal\norbit\n")
            ->and($result['resolver']['eth0']['dns'])->toBe(['198.51.100.53'])
            ->and($result['resolver']['eth0']['domains'])->toBe(['~underlay.example'])
            ->and($result['resolver']['orbit']['unrelated']['default-route'])->toBe('no')
            ->and($result['commands'])->toContain('resolvectl revert eth0', 'networkctl reconfigure eth0')->not->toContain('systemctl restart wg-quick@orbit');
    } finally {
        $harness->cleanup();
    }
});

it('waits for asynchronous networkd and resolved updates during override migration', function (): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal', previousUnderlay: true);
    try {
        $harness->delayProviderRestore();
        $result = $harness->repair();
        expect($result['exception'])->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and($result['resolver']['eth0']['dns'])->toBe(['198.51.100.53'])
            ->and(array_count_values($result['commands'])['resolvectl dns'] ?? 0)->toBeGreaterThanOrEqual(2);
    } finally {
        $harness->cleanup();
    }
});

it('restores old DNS and the orbit default-route flag if provider restoration fails', function (): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal', previousUnderlay: true);
    try {
        $before = $harness->state();
        $harness->failProviderRestore();
        $result = $harness->repair();
        expect($result['exception']?->result?->exitCode)->toBe(47)
            ->and($result['live'])->toBe($before['live'])
            ->and($result['dns'])->toBe($before['dns'])
            ->and($result['resolver'])->toBe($before['resolver'])
            ->and($result['artifacts'])->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('restores a durable uplink snapshot after failure following revert', function (bool $recoveryFailure): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal', previousUnderlay: true);
    try {
        $before = $harness->state();
        $harness->failLiveApply();
        if ($recoveryFailure) {
            $harness->failRecovery();
        }
        $result = $harness->repair();
        expect($result['exception']?->result?->exitCode)->toBe($recoveryFailure ? 46 : 45)
            ->and($result['commands'])->toContain('resolvectl revert eth0');
        if ($recoveryFailure) {
            $harness->allowFailures();
            $harness->failProviderRestore();
            $result = $harness->repair();
            expect($result['exception']?->result?->exitCode)->toBe(47);
        }
        expect($result['resolver'])->toBe($before['resolver'])
            ->and($result['live'])->toBe($before['live'])
            ->and($result['dns'])->toBe($before['dns'])
            ->and($result['artifacts'])->toBe([]);
    } finally {
        $harness->cleanup();
    }
})->with(['immediate recovery' => false, 'later recovery' => true]);

it('preserves suffix-only routing for an explicit in-VPN DNS override', function (): void {
    $harness = wireguard_peer_dns_repair_harness('10.43.0.53', 'custom.internal');

    try {
        $before = $harness->state();
        $result = $harness->repair();

        expect($result['exception'])
            ->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and($result['live'])
            ->toContain('PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~custom.internal \\~orbit')
            ->not->toContain('PreDown =')
            ->and($result['dns'])
            ->toBe("orbit\n10.43.0.53\ncustom.internal\norbit\n")
            ->and($result['resolver']['orbit'])
            ->toBe([
                'dns' => ['10.43.0.53'],
                'domains' => ['~custom.internal', '~orbit'],
                'unrelated' => $before['resolver']['orbit']['unrelated'],
            ])
            ->and($result['resolver']['eth0'])
            ->toBe($before['resolver']['eth0'])
            ->and($result['commands'])
            ->toContain(
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~custom.internal ~orbit',
            )
            ->not->toContain('ip -o route get 10.43.0.53')
            ->and($result['artifacts'])
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('repairs and reads back more than two private routing domains', function (): void {
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    Cluster::query()->create(['name' => 'prod', 'tld' => 'internal']);
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.example');
    try {
        $first = $harness->repair();
        $second = $harness->repair();
        expect($first['exception'])->toBeNull()->and($second['exception'])->toBeNull()
            ->and($second['dns'])->toBe("orbit\n10.43.0.53\ncustom.example\ninternal\norbit\ntest\n");
    } finally {
        $harness->cleanup();
    }
});

it('repairs the maximum namespace on a peer without PHP', function (): void {
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $domain = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_pad((string) $i, 61, 'x', STR_PAD_LEFT);
        $rows[] = ['name' => 'c'.$i, 'tld' => $domain];
    }
    Cluster::query()->insert($rows);
    $harness = wireguard_peer_dns_repair_harness();
    try {
        $result = $harness->repair();
        expect($result['exception'])->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and(explode("\n", trim($result['dns'])))->toHaveCount(514)
            ->and($result['resolver']['orbit']['domains'])->toHaveCount(512);
    } finally {
        $harness->cleanup();
    }
});

it('refuses contention and existing peer recovery without publication', function (string $state, string $errorCode): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $state === 'busy' ? $harness->failLock() : $harness->addPeerRecovery();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe($errorCode)
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['commands'])
            ->not->toContain('resolvectl dns orbit 10.43.0.53');
    } finally {
        $harness->cleanup();
    }
})->with([
    'active peer operation' => ['busy', 'vpn.peer_dns_busy'],
    'pending peer recovery' => ['recovery', 'vpn.peer_recovery_pending'],
]);

it('rejects an invalid candidate before changing DNS state', function (): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $harness->failCandidateValidation();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_dns_candidate_invalid')
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['artifacts'])
            ->toBe([])
            ->and($result['commands'])
            ->not->toContain('resolvectl dns orbit 10.43.0.53');
    } finally {
        $harness->cleanup();
    }
});

it('restores the exact preceding DNS state when live apply fails', function (): void {
    $harness = wireguard_peer_dns_repair_harness(null, 'custom.internal');

    try {
        $before = $harness->state();
        $harness->failLiveApply();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_dns_apply_failed')
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['private_key'])
            ->toBe($before['private_key'])
            ->and($result['resolver'])
            ->toBe($before['resolver'])
            ->and($result['artifacts'])
            ->toBe([])
            ->and($result['commands'])
            ->toContain(
                'resolvectl domain orbit ~custom.internal ~orbit',
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~orbit',
            );
    } finally {
        $harness->cleanup();
    }
});

it('restores before applying a changed DNS link when state publication fails', function (): void {
    $harness = wireguard_peer_dns_repair_harness(tld: 'custom.internal', previousUnderlay: true);

    try {
        $before = $harness->state();
        $harness->failDnsStatePublication();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_dns_apply_failed')
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['private_key'])
            ->toBe($before['private_key'])
            ->and($result['resolver'])
            ->toBe($before['resolver'])
            ->and($result['artifacts'])
            ->toBe([])
            ->and($result['commands'])
            ->toContain(
                'resolvectl dns eth0 10.0.0.2',
                'resolvectl domain eth0 ~orbit',
            )
            ->not->toContain('resolvectl revert orbit')
            ->not->toContain(
                'resolvectl dns eth0 192.0.2.53',
                'resolvectl domain eth0 ~custom.internal ~orbit',
            );
    } finally {
        $harness->cleanup();
    }
});

it('retains explicit recovery state and completes it on retry', function (): void {
    $harness = wireguard_peer_dns_repair_harness(null, 'custom.internal');

    try {
        $before = $harness->state();
        $harness->failLiveApply();
        $harness->failRecovery();

        $failed = $harness->repair();

        expect($failed['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($failed['exception']->errorCode)
            ->toBe('vpn.peer_dns_recovery_failed')
            ->and($failed['live'])
            ->toBe($before['live'])
            ->and($failed['dns'])
            ->toBe($before['dns'])
            ->and($failed['resolver']['orbit']['unrelated'])
            ->toBe($before['resolver']['orbit']['unrelated'])
            ->and($failed['resolver']['eth0'])
            ->toBe($before['resolver']['eth0'])
            ->and($failed['artifacts'])
            ->toContain(
                '.orbit.conf.rollback',
                '.orbit.dns-link.rollback',
                '.orbit.peer-transaction',
            );

        $harness->allowFailures();
        $retried = $harness->repair();

        expect($retried['exception'])
            ->toBeNull()
            ->and($retried['live'])
            ->toContain('resolvectl domain %i \\~custom.internal \\~orbit')
            ->and($retried['dns'])
            ->toBe("orbit\n10.43.0.53\ncustom.internal\norbit\n")
            ->and($retried['resolver']['orbit'])
            ->toBe([
                'dns' => ['10.43.0.53'],
                'domains' => ['~custom.internal', '~orbit'],
                'unrelated' => $before['resolver']['orbit']['unrelated'],
            ])
            ->and($retried['resolver']['eth0'])
            ->toBe($before['resolver']['eth0'])
            ->and($retried['artifacts'])
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

function wireguard_peer_dns_repair_harness(
    ?string $dnsServerOverride = null,
    ?string $tld = null,
    bool $hooklessResolverHooks = false,
    bool $previousUnderlay = false,
): object {
    $root = sys_get_temp_dir().'/orbit-peer-dns-repair-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/wireguard", 0o700, true);
    $filesystem->makeDirectory("{$root}/bin", 0o700, true);
    $filesystem->makeDirectory("{$root}/state", 0o700, true);
    $filesystem->makeDirectory("{$root}/state/resolver/orbit", 0o700, true);
    $filesystem->makeDirectory("{$root}/state/resolver/eth0", 0o700, true);
    $filesystem->makeDirectory("{$root}/lock", 0o700, true);

    wireguard_peer_dns_repair_write_resolver_state(
        root: $root,
        link: 'orbit',
        dns: ['10.43.0.53'],
        domains: ['~orbit'],
        unrelated: [
            'default-route' => 'no',
            'llmnr' => 'resolve',
            'mdns' => 'yes',
            'dnssec' => 'allow-downgrade',
            'dnsovertls' => 'opportunistic',
            'nta' => 'corp.example',
        ],
    );
    wireguard_peer_dns_repair_write_resolver_state(
        root: $root,
        link: 'eth0',
        dns: ['198.51.100.53'],
        domains: ['~underlay.example'],
        unrelated: [
            'default-route' => $previousUnderlay ? 'no' : 'yes',
            'llmnr' => 'yes',
            'mdns' => 'resolve',
            'dnssec' => 'yes',
            'dnsovertls' => 'no',
            'nta' => 'underlay.example',
        ],
    );

    $originalLive = <<<'CONF'
        [Interface]
        PrivateKey = stable-private-key
        Address = 10.43.0.7/24
        PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \~orbit
        CONF;
    if (! $hooklessResolverHooks) {
        $originalLive .= "\nPreDown = resolvectl revert %i";
    }
    $originalLive .= <<<'CONF'


        [Peer]
        PublicKey = stable-server-key
        Endpoint = 192.0.2.1:51820
        AllowedIPs = 10.43.0.0/24
        PersistentKeepalive = 25
        CONF;
    $originalLive .= "\n";
    $originalDns = "orbit\n10.43.0.53\norbit\n";
    if ($previousUnderlay) {
        $originalLive = preg_replace('/^(PostUp|PreDown) = .*\n/m', '', $originalLive);
        $originalDns = "eth0\n10.0.0.2\norbit\n";
        file_put_contents("{$root}/state/resolver/orbit/dns", '');
        file_put_contents("{$root}/state/resolver/orbit/domain", '');
        file_put_contents("{$root}/state/resolver/orbit/default-route", 'yes');
        file_put_contents("{$root}/state/resolver/eth0/dns", "10.0.0.2\n");
        file_put_contents("{$root}/state/resolver/eth0/domain", "~orbit\n");
    }
    file_put_contents("{$root}/wireguard/orbit.conf", $originalLive);
    file_put_contents("{$root}/wireguard/orbit.dns-link", $originalDns);
    file_put_contents("{$root}/wireguard/orbit.key", 'stable-private-key-material');
    file_put_contents("{$root}/wireguard/private.key", str_repeat(string: 'S', times: 43).'=');
    file_put_contents("{$root}/wireguard/public.key", str_repeat(string: 'P', times: 43).'=');

    wireguard_peer_dns_repair_shim($root, 'flock', <<<'SH'
        if [ -f "$ORBIT_TEST_ROOT/state/lock-failure" ]; then exit 1; fi
        exit 0
        SH);
    wireguard_peer_dns_repair_shim($root, 'systemctl', <<<'SH'
        printf '%s\n' "systemctl $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "${1:-}" = is-active ] && [ "${2:-}" = --quiet ] && [ "${3:-}" = wg-quick@orbit ]; then exit 0; fi
        exit 1
        SH);
    wireguard_peer_dns_repair_shim($root, 'wg', <<<'SH'
        printf '%s\n' "wg $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "$*" = 'show orbit public-key' ]; then
            printf '%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
            exit 0
        fi
        exit 1
        SH);
    wireguard_peer_dns_repair_shim($root, 'wg-quick', <<<'SH'
        printf '%s\n' "wg-quick $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ -f "$ORBIT_TEST_ROOT/state/candidate-failure" ]; then exit 1; fi
        [ "${1:-}" = strip ]
        SH);
    wireguard_peer_dns_repair_shim($root, 'php', 'exit 127');
    wireguard_peer_dns_repair_shim($root, 'ip', <<<'SH'
        printf '%s\n' "ip $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "${1:-}" = -o ] && [ "${2:-}" = route ] && [ "${3:-}" = get ]; then
            printf '%s dev eth0 src 192.0.2.7\n' "${4:-}"
            exit 0
        fi
        exit 1
        SH);
    wireguard_peer_dns_repair_shim($root, 'resolvectl', <<<'SH'
        printf '%s\n' "resolvectl $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ -f "$ORBIT_TEST_ROOT/state/apply-failure" ] && [ "$*" = 'domain orbit ~custom.internal ~orbit' ]; then exit 1; fi
        if [ -f "$ORBIT_TEST_ROOT/state/recovery-failure" ] && { [ "$*" = 'domain orbit ~orbit' ] || [ "$*" = 'domain eth0 ~orbit' ]; }; then exit 1; fi

        if [ "$*" = dns ]; then
            if [ -f "$ORBIT_TEST_ROOT/state/provider-pending" ]; then
                rm -f "$ORBIT_TEST_ROOT/state/provider-pending"
                printf '198.51.100.53\n' > "$ORBIT_TEST_ROOT/state/resolver/eth0/dns"
                printf '~underlay.example\n' > "$ORBIT_TEST_ROOT/state/resolver/eth0/domain"
                printf 'Global: \nLink 7 (eth0): \n'
                exit 0
            fi
            printf 'Global: %s\n' "$(cat "$ORBIT_TEST_ROOT/state/global-dns" 2>/dev/null || true)"
            for dir in "$ORBIT_TEST_ROOT/state/resolver/"*; do
                printf 'Link 7 (%s): %s\n' "${dir##*/}" "$(cat "$dir/dns")"
            done
            exit 0
        fi
        operation=$1
        link=$2
        shift 2
        resolver="$ORBIT_TEST_ROOT/state/resolver/$link"
        [ -d "$resolver" ] || mkdir -p "$resolver"
        case "$operation" in
            default-route|llmnr|mdns|dnssec|dnsovertls|nta)
                if [ "$#" = 0 ]; then
                    printf 'Link 7 (%s): %s\n' "$link" "$(cat "$resolver/$operation")"
                else
                    printf '%s\n' "$1" > "$resolver/$operation"
                fi
                ;;
            dns|domain)
                if [ "$operation" = dns ] && [ "$link" = eth0 ] && [ "$#" = 0 ] && [ -f "$ORBIT_TEST_ROOT/state/provider-pending" ]; then
                    rm -f "$ORBIT_TEST_ROOT/state/provider-pending"
                    printf '198.51.100.53\n' > "$resolver/dns"
                    printf '~underlay.example\n' > "$resolver/domain"
                    printf 'Link 7 (eth0): \n'
                    exit 0
                fi
                if [ "$#" = 0 ]; then
                    printf 'Link 7 (%s): %s\n' "$link" "$(paste -sd ' ' "$resolver/$operation")"
                    exit 0
                fi
                if [ "$link" = eth0 ]; then touch "$resolver/transient"; fi
                : > "$resolver/$operation"
                for value in "$@"; do
                    if [ -n "$value" ]; then
                        printf '%s\n' "$value" >> "$resolver/$operation"
                    fi
                done
                ;;
            revert)
                rm -f "$resolver/transient"
                : > "$resolver/dns"
                : > "$resolver/domain"
                for setting in default-route llmnr mdns dnssec dnsovertls nta; do
                    printf 'default\n' > "$resolver/$setting"
                done
                ;;
            *) exit 1 ;;
        esac
        SH);
    wireguard_peer_dns_repair_shim($root, 'networkctl', <<<'SH'
        printf '%s\n' "networkctl $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "${1:-}" = --no-legend ]; then
            if [ -f "$ORBIT_TEST_ROOT/state/provider-pending" ]; then
                printf '2 eth0 ether routable configuring\n'
            else
                printf '2 eth0 ether routable configured\n'
            fi
            exit 0
        fi
        if [ -f "$ORBIT_TEST_ROOT/state/provider-failure" ]; then exit 1; fi
        if [ -f "$ORBIT_TEST_ROOT/state/resolver/eth0/transient" ]; then exit 0; fi
        printf 'yes\n' > "$ORBIT_TEST_ROOT/state/resolver/eth0/default-route"
        if [ -f "$ORBIT_TEST_ROOT/state/provider-delay" ]; then
            touch "$ORBIT_TEST_ROOT/state/provider-pending"
            exit 0
        fi
        printf '198.51.100.53\n' > "$ORBIT_TEST_ROOT/state/resolver/eth0/dns"
        printf '~underlay.example\n' > "$ORBIT_TEST_ROOT/state/resolver/eth0/domain"
        SH);
    wireguard_peer_dns_repair_shim($root, 'chown', <<<'SH'
        exit 0
        SH);
    wireguard_peer_dns_repair_shim($root, 'mv', <<<'SH'
        if [ -f "$ORBIT_TEST_ROOT/state/dns-state-publication-failure" ] \
            && [ "${3:-}" = "$ORBIT_TEST_ROOT/wireguard/.orbit.dns-link.candidate" ] \
            && [ "${4:-}" = "$ORBIT_TEST_ROOT/wireguard/orbit.dns-link" ]; then
            exit 1
        fi
        exec {{host:mv}} "$@"
        SH);

    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(
        subnet: '10.43.0.0/24',
        endpoint: '192.0.2.1:51820',
        dnsServer: '10.43.0.53',
    );
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
        'dns_server_override' => $dnsServerOverride,
        'tld' => $tld,
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $peer->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $ssh = new class($root) implements SshExecutor
    {
        /** @var list<SshConnection> */
        public array $connections = [];

        public function __construct(
            private readonly string $root,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->connections[] = $connection;
            $input = str_replace(
                ['/etc/wireguard', '/run/lock/orbit-wireguard-peer.lock'],
                ["{$this->root}/wireguard", "{$this->root}/lock/orbit-wireguard-peer.lock"],
                $command->input ?? '',
            );
            $process = proc_open(
                [TestToolchain::bash(), '-seu', '--', ...array_slice($command->arguments, 4)],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $this->root,
                [
                    'PATH' => "{$this->root}/bin:".TestToolchain::path(),
                    'ORBIT_TEST_ROOT' => $this->root,
                ],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the DNS repair shell fixture.');
            }

            $written = 0;
            while ($written < mb_strlen($input, '8bit')) {
                $bytes = @fwrite($pipes[0], substr($input, $written));

                if (! is_int($bytes) || $bytes === 0) {
                    break;
                }

                $written += $bytes;
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return new CommandResult(
                $exitCode,
                $stdout === false ? '' : $stdout,
                $stderr === false ? '' : $stderr,
                1,
                false,
            );
        }
    };
    $repairer = new NativeWireGuardPeerDnsRepairer(
        configuration: new VpnConfigurationRepository($settings, $root),
        ssh: $ssh,
        sshKeys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/gateway/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'unused';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/gateway/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );

    return new class($root, $filesystem, $repairer, $peer, $ssh)
    {
        public function __construct(
            private readonly string $root,
            private readonly Filesystem $filesystem,
            private readonly NativeWireGuardPeerDnsRepairer $repairer,
            private readonly Node $peer,
            private readonly object $ssh,
        ) {}

        public function repair(bool $tldConvergence = false): array
        {
            try {
                if ($tldConvergence) {
                    new RemoteAppDevTldRouteManager($this->repairer)->converge($this->peer);
                } else {
                    $this->repairer->repair($this->peer);
                }
                $exception = null;
            } catch (NodeProvisioningException|RuntimeConvergenceException $failure) {
                $exception = $failure;
            }

            return ['exception' => $exception, ...$this->state()];
        }

        public function state(): array
        {
            return [
                'live' => (string) file_get_contents("{$this->root}/wireguard/orbit.conf"),
                'dns' => (string) file_get_contents("{$this->root}/wireguard/orbit.dns-link"),
                'private_key' => (string) file_get_contents("{$this->root}/wireguard/orbit.key"),
                'resolver' => [
                    'orbit' => $this->resolverLinkState('orbit'),
                    'eth0' => $this->resolverLinkState('eth0'),
                ],
                'commands' => is_file("{$this->root}/commands.log")
                    ? array_values(array_filter(explode("\n", trim((string) file_get_contents("{$this->root}/commands.log")))))
                    : [],
                'connections' => array_map(
                    static fn (SshConnection $connection): array => (array) $connection,
                    $this->ssh->connections,
                ),
                'artifacts' => array_values(array_map(
                    basename(...),
                    glob("{$this->root}/wireguard/.orbit*") ?: [],
                )),
            ];
        }

        private function resolverLinkState(string $link): array
        {
            $root = "{$this->root}/state/resolver/{$link}";

            return [
                'dns' => $this->resolverValues("{$root}/dns"),
                'domains' => $this->resolverValues("{$root}/domain"),
                'unrelated' => array_map(
                    static fn (string $setting): string => trim((string) file_get_contents("{$root}/{$setting}")),
                    array_combine(
                        ['default-route', 'llmnr', 'mdns', 'dnssec', 'dnsovertls', 'nta'],
                        ['default-route', 'llmnr', 'mdns', 'dnssec', 'dnsovertls', 'nta'],
                    ),
                ),
            ];
        }

        /** @return list<string> */
        private function resolverValues(string $path): array
        {
            return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
        }

        public function setRootPolicy(): void
        {
            $live = (string) file_get_contents("{$this->root}/wireguard/orbit.conf");
            file_put_contents("{$this->root}/wireguard/orbit.conf", str_replace('\\~orbit', '\\~.', $live));
            file_put_contents("{$this->root}/wireguard/orbit.dns-link", "orbit\n10.43.0.53\n.\n");
            file_put_contents("{$this->root}/state/resolver/orbit/domain", "~.\n");
            file_put_contents("{$this->root}/state/resolver/orbit/default-route", "yes\n");
        }

        public function setPublicDns(string $linkDns, string $globalDns = ''): void
        {
            file_put_contents("{$this->root}/state/resolver/eth0/dns", $linkDns);
            file_put_contents("{$this->root}/state/global-dns", $globalDns);
        }

        public function failLock(): void
        {
            file_put_contents("{$this->root}/state/lock-failure", '1');
        }

        public function addPeerRecovery(): void
        {
            file_put_contents("{$this->root}/wireguard/.orbit.peer-transaction", "active\nenabled\n1\n1\n");
            file_put_contents("{$this->root}/wireguard/.orbit.conf.rollback", 'peer backup');
            file_put_contents("{$this->root}/wireguard/.orbit.dns-link.rollback", 'peer dns backup');
        }

        public function failCandidateValidation(): void
        {
            file_put_contents("{$this->root}/state/candidate-failure", '1');
        }

        public function failLiveApply(): void
        {
            file_put_contents("{$this->root}/state/apply-failure", '1');
        }

        public function delayProviderRestore(): void
        {
            file_put_contents("{$this->root}/state/provider-delay", '1');
        }

        public function failProviderRestore(): void
        {
            file_put_contents("{$this->root}/state/provider-failure", '1');
        }

        public function failDnsStatePublication(): void
        {
            file_put_contents("{$this->root}/state/dns-state-publication-failure", '1');
        }

        public function failRecovery(): void
        {
            file_put_contents("{$this->root}/state/recovery-failure", '1');
        }

        public function allowFailures(): void
        {
            $this->filesystem->delete([
                "{$this->root}/state/apply-failure",
                "{$this->root}/state/recovery-failure",
            ]);
        }

        public function cleanup(): void
        {
            $this->filesystem->deleteDirectory($this->root);
        }
    };
}

function wireguard_peer_dns_repair_shim(string $root, string $name, string $body): void
{
    $path = "{$root}/bin/{$name}";
    file_put_contents($path, HostBinary::expand('#!'.TestToolchain::bash()."\nset -eu\n{$body}\n"));
    chmod($path, 0o700);
}

/**
 * @param  list<string>  $dns
 * @param  list<string>  $domains
 * @param  array<string, string>  $unrelated
 */
function wireguard_peer_dns_repair_write_resolver_state(
    string $root,
    string $link,
    array $dns,
    array $domains,
    array $unrelated,
): void {
    $resolver = "{$root}/state/resolver/{$link}";
    file_put_contents("{$resolver}/dns", implode("\n", $dns)."\n");
    file_put_contents("{$resolver}/domain", implode("\n", $domains)."\n");

    foreach ($unrelated as $setting => $value) {
        file_put_contents("{$resolver}/{$setting}", "{$value}\n");
    }
}

it('refuses split repair without an uplink and accepts global or per-link DNS', function (string $link, string $global, bool $allowed): void {
    $harness = wireguard_peer_dns_repair_harness();
    try {
        $harness->setRootPolicy();
        $harness->setPublicDns($link, $global);
        $before = $harness->state();
        $result = $harness->repair();
        if ($allowed) {
            expect($result['exception'])->toBeNull($result['exception']?->result?->stderr ?? '');
        } else {
            expect($result['exception']?->result?->exitCode)->toBe(47)
                ->and($result['exception']?->errorCode)->toBe('vpn.peer_dns_uplink_missing')
                ->and($result['live'])->toBe($before['live'])
                ->and($result['dns'])->toBe($before['dns'])
                ->and($result['resolver'])->toBe($before['resolver'])
                ->and($result['artifacts'])->toBe([]);
        }
    } finally {
        $harness->cleanup();
    }
})->with([['', '', false], ['10.43.0.53', '', false], ['127.0.0.53', '', false], ['', '198.51.100.53', true], ['198.51.100.53', '', true], ['', '2001:db8::53#resolver.example', true], ['fe80::53%eth0', '', true], ['', '::1', false], ['999.0.0.53', '', false]]);
