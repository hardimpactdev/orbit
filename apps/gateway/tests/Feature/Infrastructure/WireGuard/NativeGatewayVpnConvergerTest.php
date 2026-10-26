<?php

declare(strict_types=1);

use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\WireGuard\NativeGatewayVpnConverger;
use App\Infrastructure\WireGuard\UplinkDnsResolvers;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;

it('activates the gateway WireGuard address through a validated atomic server config', function (): void {
    [$converger, $processes, $orbitHome, $agents] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments)
            ->all();

        assert_gateway_generated_files(processes: $processes, orbitHome: $orbitHome);
        expect($agents->calls[0]['input']['config'])->toBe(file_get_contents($orbitHome.'/generated/wireguard/orbit.conf'));
        assert_gateway_publication_commands(
            processes: $processes,
            arguments: $arguments,
            orbitHome: $orbitHome,
        );
        expect($agents->installs)->toBe([]);
        expect($agents->commands())->toBe(['wireguard.bootstrap-hub', 'wireguard.remove-dns-ordering', 'firewall.gateway-vpn']);
        expect($arguments[1])->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN])
            ->and($processes->calls[1]->input)
            ->toBe(new GatewayPrivateDnsResolver()->convergeScript('10.44.0.1', 'orbit'))
            ->and($arguments)->toHaveCount(2);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects invalid subnet inputs before host mutation', function (
    string $subnet,
    string $address,
    string $message,
): void {
    [$converger, $processes, $orbitHome, $agents] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => $address,
    ]);

    try {
        expect(fn () => $converger->converge(
            $node,
            gateway_bootstrap_data(wireguardSubnet: $subnet, wireguardIp: $address),
        ))
            ->toThrow(function (NodeProvisioningException $exception) use ($message): void {
                expect($exception->errorCode)
                    ->toBe('vpn.configuration_invalid')
                    ->and($exception->getMessage())
                    ->toBe($message);
            });

        expect($agents->calls)->toBeEmpty()->and($agents->installs)->toBeEmpty();
        expect($processes->calls)
            ->toBe([])
            ->and(is_file($orbitHome.'/generated/wireguard/orbit.conf'))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'subnet host bits' => [
        '10.44.0.1/24',
        '10.44.0.2',
        'WireGuard subnet [10.44.0.1/24] is invalid.',
    ],
    'bad prefix' => [
        '10.44.0.0/31',
        '10.44.0.1',
        'WireGuard subnet [10.44.0.0/31] is invalid.',
    ],
]);

function assert_gateway_generated_files(GatewayVpnFakeProcessRunner $processes, string $orbitHome): void
{
    expect(file_get_contents($orbitHome.'/generated/wireguard/orbit.conf'))
        ->toContain(
            'Address = 10.44.0.1/24',
            'ListenPort = 51820',
            'PrivateKey = SERVER_PRIVATE',
        )
        ->not->toContain('[Peer]');

    expect($processes->observedProjectionLock)->toBeTrue();
    expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
        ->toBe(gateway_orbit_vpn_dnsmasq_conf())
        ->not->toContain('bind-dynamic', 'interface=orbit', 'server=127.0.0.53');
}

/** @param list<list<string>> $arguments */
function assert_gateway_publication_commands(
    GatewayVpnFakeProcessRunner $processes,
    array $arguments,
    string $orbitHome,
): void {
    expect($arguments[0])->toBe(['sudo', 'bash', '-seu']);
    expect($processes->calls[0]->input)
        ->toContain(
            'exec 9>/run/lock/orbit-dnsmasq.lock',
            'flock -w 30 9',
            'dnsmasq --test --conf-file="$validation/dnsmasq.conf"',
            'cmp -s -- "$validation/fragments/orbit-vpn.conf" "$managed"',
            'if systemctl is-active --quiet dnsmasq; then',
            'if ! restart_dnsmasq; then',
            'journalctl --unit=dnsmasq --lines=20 --no-pager >&2 || true',
            'systemctl restart dnsmasq || true',
        );
    expect(base64_decode(
        Str::match('/\x27([A-Za-z0-9+\/=]+)\x27 \| base64 --decode/', $processes->calls[0]->input ?? ''),
        strict: true,
    ))->toContain('listen-address=127.0.0.55', 'bind-interfaces');
}

it('sends Gateway VPN and recovery intent through the installed agent, including private DNS interfaces', function (): void {
    [$converger, $processes, $home, $agents] = gateway_vpn_converger();
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'public_ssh_port' => 2222, 'wireguard_ip' => '10.44.0.1']);
    try {
        $converger->converge($node, gateway_bootstrap_data(privateInterface: 'eth3'));
        $input = $agents->calls[2]['input'];
        expect($agents->installs)->toBe([]);
        expect($agents->commands())->toBe(['wireguard.bootstrap-hub', 'wireguard.remove-dns-ordering', 'firewall.gateway-vpn']);
        expect($input['recovery'])->toBe([
            'comment' => 'orbit:public-ssh-recovery', 'action' => 'allow', 'direction' => 'in',
            'source' => 'any', 'destination' => 'any', 'port' => '2222', 'protocol' => 'tcp',
            'in_interface' => null, 'out_interface' => null, 'family' => null,
        ]);
        $desired = collect($input['desired'])->keyBy('comment');
        expect($desired->keys()->all())->toBe([
            'orbit:vpn-wireguard', 'orbit:vpn-ssh', 'orbit:vpn-dns-udp-orbit', 'orbit:vpn-dns-tcp-orbit',
            'orbit:vpn-dns-udp-eth3', 'orbit:vpn-dns-tcp-eth3', 'orbit:gateway-https', 'orbit:vpn-peer-forwarding',
        ]);
        expect($desired['orbit:vpn-wireguard'])->toMatchArray(['port' => '51820', 'protocol' => 'udp', 'source' => 'any', 'family' => null]);
        expect($desired['orbit:vpn-ssh'])->toMatchArray(['destination' => '10.44.0.1', 'port' => '22', 'protocol' => 'tcp', 'in_interface' => 'orbit', 'family' => 'v4']);
        foreach (['orbit', 'eth3'] as $interface) {
            foreach (['udp', 'tcp'] as $protocol) {
                expect($desired["orbit:vpn-dns-{$protocol}-{$interface}"])->toMatchArray(['port' => '53', 'protocol' => $protocol, 'in_interface' => $interface, 'family' => null]);
            }
        }
        expect($desired['orbit:gateway-https'])->toMatchArray(['port' => '443', 'protocol' => 'tcp', 'in_interface' => 'orbit']);
        expect($desired['orbit:vpn-peer-forwarding'])->toMatchArray(['source' => '10.44.0.0/24', 'destination' => '10.44.0.0/24', 'direction' => 'fwd', 'in_interface' => 'orbit', 'out_interface' => 'orbit']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('maps native Gateway firewall refusals to their provisioning boundary', function (string $code, string $step): void {
    [$converger, , $home, $agents] = gateway_vpn_converger();
    $agents->refuse('firewall.gateway-vpn', $code);
    try {
        expect(fn () => $converger->converge(Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']), gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception) use ($code, $step): void {
                expect($exception->errorCode)->toBe($code)->and($exception->step)->toBe($step);
            });
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    ['vpn.firewall_status_failed', 'vpn-firewall-status'],
    ['vpn.firewall_status_invalid', 'vpn-firewall-status'],
    ['vpn.firewall_recovery_probe_failed', 'vpn-firewall-recovery-probe'],
    ['vpn.firewall_enable_failed', 'vpn-firewall-enable'],
    ['vpn.firewall_rule_failed', 'vpn-firewall'],
    ['vpn.firewall_probe_failed', 'vpn-firewall-probe'],
    ['vpn.firewall_recovery_remove_failed', 'vpn-firewall-recovery-remove'],
]);

it('writes mesh dnsmasq upstreams from visible uplink resolvers', function (): void {
    [$converger, , $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        gateway_write_host_file(
            $orbitHome,
            'run/systemd/resolve/resolv.conf',
            "nameserver 192.0.2.53\nnameserver 192.0.2.54\n",
        );
        $converger->converge($node, gateway_bootstrap_data());

        expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
            ->toBe(gateway_orbit_vpn_dnsmasq_conf(['192.0.2.53', '192.0.2.54']))
            ->not->toContain('server=1.1.1.1', 'server=8.8.8.8', 'server=127.0.0.53', 'bind-dynamic');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps the documented recursive fallback when only a stub resolver is visible', function (): void {
    [$converger, , $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        gateway_write_host_file(
            $orbitHome,
            'run/systemd/resolve/resolv.conf',
            "nameserver 127.0.0.53\noptions edns0 trust-ad\n",
        );
        $converger->converge($node, gateway_bootstrap_data());

        expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
            ->toBe(gateway_orbit_vpn_dnsmasq_conf())
            ->not->toContain('server=127.0.0.53');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('reports the bounded dnsmasq journal tail when the managed restart fails', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        [$exitCode, $stderr] = gateway_dnsmasq_restart_failure($processes->calls[0]->input ?? '');

        expect($exitCode)
            ->toBe(1)
            ->and($stderr)
            ->toContain(
                'dnsmasq did not restart. Last journal lines:',
                'journalctl --unit=dnsmasq --lines=20 --no-pager',
                'dnsmasq: cannot set --bind-interfaces and --bind-dynamic',
            );
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('finishes bootstrap VPN convergence and logs a warning when the resolver step fails', function (): void {
    Log::spy();
    [$converger, $processes, $orbitHome] = gateway_vpn_converger();
    $processes->failResolver = true;
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());

        expect(end($processes->calls)->arguments)->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $context === [
                'error_code' => 'vpn.dns_resolver_failed',
                'exit_code' => 1,
            ]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not touch UFW when dnsmasq convergence fails and includes rollback', function (): void {
    [$converger, $processes, $orbitHome, $agents] = gateway_vpn_converger(failDns: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-dns')
                    ->and($exception->errorCode)
                    ->toBe('vpn.dns_config_failed')
                    ->and($exception->result?->stderr)
                    ->toContain(
                        'dnsmasq did not restart. Last journal lines:',
                        'dnsmasq: cannot set --bind-interfaces and --bind-dynamic',
                    );
            });
        $dns = Collection::make($processes->calls)
            ->first(
                static fn (ProcessInvocation $call): bool => (
                    $call->arguments === ['sudo', 'bash', '-seu']
                    && str_contains($call->input ?? '', 'managed=/etc/dnsmasq.d/orbit-vpn.conf')
                ),
            );
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect($dns)
            ->toBeInstanceOf(ProcessInvocation::class)
            ->and($dns?->input)
            ->toContain(
                'install -o root -g root -m 0644 -- "$backup" "$managed"',
                'rm -f -- "$managed"',
                'systemctl restart dnsmasq || true',
            )
            ->and($agents->commands())->toBe(['wireguard.bootstrap-hub', 'wireguard.remove-dns-ordering'])
            ->and($agents->installs)->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('maps native WireGuard bootstrap refusals and starts no later step', function (string $refusal, string $code, string $step): void {
    [$converger, $processes, $home, $agents] = gateway_vpn_converger();
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']);
    $agents->refuse('wireguard.bootstrap-hub', $refusal);
    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception) use ($code, $step): void {
                expect($exception->errorCode)->toBe($code)->and($exception->step)->toBe($step);
            });
        expect($agents->commands())->toBe(['wireguard.bootstrap-hub']);
        expect($processes->calls)->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    ['wireguard.hub_install_failed', 'vpn.server_config_install_failed', 'wireguard-server-install'],
    ['wireguard.hub_config_invalid', 'vpn.server_config_invalid', 'wireguard-server-validate'],
    ['wireguard.forwarding_install_failed', 'vpn.forwarding_config_install_failed', 'wireguard-forwarding-install'],
    ['wireguard.forwarding_config_invalid', 'vpn.forwarding_config_invalid', 'wireguard-forwarding-apply'],
    ['wireguard.hub_start_failed', 'vpn.server_start_failed', 'wireguard-server-restart'],
]);

/**
 * Run the rendered dnsmasq restart helper with a failing `systemctl` shim and
 * a `journalctl` shim that prints the conflict the unit reports.
 *
 * @return array{int, string}
 */
function gateway_dnsmasq_restart_failure(string $script): array
{
    $root = sys_get_temp_dir().'/orbit-dnsmasq-restart-'.Str::uuid();
    mkdir(directory: $root.'/bin', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $root.'/bin/systemctl',
        data: "#!/usr/bin/env bash\nprintf 'Job for dnsmasq.service failed.\\n' >&2\nexit 1\n",
    );
    file_put_contents(filename: $root.'/bin/journalctl', data: <<<'BASH'
        #!/usr/bin/env bash
        printf 'journalctl %s\n' "$*"
        printf 'dnsmasq: cannot set --bind-interfaces and --bind-dynamic\n'
        BASH);
    chmod($root.'/bin/systemctl', permissions: 0o755);
    chmod($root.'/bin/journalctl', permissions: 0o755);
    $helper = Str::match('/^restart_dnsmasq\(\) \{$.*?^\}$/ms', $script);

    try {
        $process = new Process(['bash', '-seu'], $root, [
            'PATH' => $root.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput($helper."\nrestart_dnsmasq\n");
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getErrorOutput()];
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
}

/** @param list<string> $servers */
function gateway_orbit_vpn_dnsmasq_conf(array $servers = UplinkDnsResolvers::FALLBACK): string
{
    $upstream = implode("\n", array_map(
        static fn (string $server): string => "server={$server}",
        $servers,
    ));

    return "# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\ndomain-needed\nbogus-priv\nno-resolv\n{$upstream}\nlocal=/orbit/\nhost-record=gateway.orbit,10.44.0.1\n";
}

function gateway_write_host_file(string $orbitHome, string $relative, string $contents): void
{
    $path = $orbitHome.'/host/'.$relative;
    new Filesystem()->ensureDirectoryExists(dirname($path));
    file_put_contents($path, $contents);
}

/** @return array{NativeGatewayVpnConverger, GatewayVpnFakeProcessRunner, string, FakeAgentCommandRunner} */
function gateway_vpn_converger(

    bool $failDns = false,

): array {
    $orbitHome = sys_get_temp_dir().'/orbit-gateway-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    mkdir(directory: $orbitHome.'/host', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');
    $processes = new GatewayVpnFakeProcessRunner(

        failDns: $failDns,

        orbitHome: $orbitHome,
    );

    $agents = new FakeAgentCommandRunner()->answer('firewall.gateway-vpn', ['matches' => true])->answer('wireguard.remove-dns-ordering', ['changed' => false]);
    $agents->answer('wireguard.bootstrap-hub', static function () use ($orbitHome, $processes): array {
        $lock = fopen($orbitHome.'/locks/wireguard-server.lock', 'c+');
        $acquired = flock($lock, LOCK_EX | LOCK_NB);
        $processes->observedProjectionLock = ! $acquired;
        fclose($lock);

        return [];
    });

    return [
        new NativeGatewayVpnConverger(
            renderer: new WireGuardServerConfigRenderer,
            files: new ProtectedFileWriter,
            processes: $processes,
            agents: $agents,
            orbitHome: $orbitHome,
            uplinkResolvers: new UplinkDnsResolvers($orbitHome.'/host'),
        ),
        $processes,
        $orbitHome,
        $agents,
    ];
}

function gateway_bootstrap_data(
    ?string $privateInterface = null,
    string $wireguardSubnet = '10.44.0.0/24',
    string $wireguardIp = '10.44.0.1',
): BootstrapGatewayData {
    return new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: $wireguardIp,
        wireguardSubnet: $wireguardSubnet,
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'orbit',
        privateInterface: $privateInterface,
    );
}

final class GatewayVpnFakeProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $calls = [];

    public bool $observedProjectionLock = false;

    public bool $failResolver = false;

    public function __construct(

        private readonly bool $failDns,

        private readonly string $orbitHome,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls[] = $invocation;

        $failure = $this->configuredFailure($invocation);

        if ($failure instanceof CommandResult) {
            return $failure;
        }

        return new CommandResult(0, '', '', 2, false);
    }

    private function configuredFailure(ProcessInvocation $invocation): ?CommandResult
    {
        if ($this->failResolver && ($invocation->arguments[4] ?? null) === GatewayPrivateDnsResolver::DROP_IN) {
            return new CommandResult(1, '', 'Failed to set DNS configuration', 2, false);
        }

        if (
            $this->failDns
            && $invocation->arguments === ['sudo', 'bash', '-seu']
            && str_contains($invocation->input ?? '', 'managed=/etc/dnsmasq.d/orbit-vpn.conf')
        ) {
            return new CommandResult(
                1,
                '',
                "dnsmasq did not restart. Last journal lines:\ndnsmasq: cannot set --bind-interfaces and --bind-dynamic\n",
                2,
                false,
            );
        }

        return null;
    }
}

it('keeps Gateway firewall execution behind its typed agent command', function (): void {
    $source = file_get_contents(app_path('Infrastructure/WireGuard/NativeGatewayVpnConverger.php'));
    expect($source)->toContain("'firewall.gateway-vpn'")
        ->not->toContain('UfwStatusParser', 'UfwStoredRule', "'ufw'", 'ruleNumbers', 'wg-quick', 'sysctl', 'backupLiveConfig');
});

it('stops before DNS publication when native ordering removal refuses', function (): void {
    [$converger, $processes, $orbitHome, $agents] = gateway_vpn_converger();
    $agents->refuse('wireguard.remove-dns-ordering', 'service.ordering_unsafe', ['path' => '/etc/systemd/system/dnsmasq.service.d/orbit-vpn.conf']);
    $node = new Node(['name' => 'gateway', 'wireguard_ip' => '10.44.0.1']);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('vpn-dns-ordering')
                    ->and($exception->errorCode)->toBe('vpn.dns_config_failed');
            });
        expect($agents->commands())->toBe(['wireguard.bootstrap-hub', 'wireguard.remove-dns-ordering']);
        expect($processes->calls)->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});
