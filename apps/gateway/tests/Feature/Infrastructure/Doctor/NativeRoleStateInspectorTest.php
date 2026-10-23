<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\CiRunnerHostProgram;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;

it('sends each role package and service intent to the agent and compares its firewall', function (RoleName $role, array $packages, array $services, array $comments): void {
    $agents = role_runtime_agents(role_inspector_ufw($comments));
    $ssh = new RoleInspectorSshExecutor([]);

    $state = role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment($role));

    expect($state->packagesPresent)->toBeTrue();
    expect($state->servicesActive)->toBeTrue();
    expect($state->firewallProjectionMatches)->toBeTrue();
    expect($state->caddyVersion)->toBe(in_array('caddy', $packages, true) ? 'v2.11.4' : null);
    expect($agents->calls[0]['input'])->toBe(['packages' => $packages, 'services' => $services]);
    expect($agents->calls[1]['input'])->toBe(['stored' => false]);
    expect($ssh->calls)->toBe([]);
})->with([
    'gateway' => [
        RoleName::Gateway,
        ['ca-certificates', 'caddy', 'logrotate'],
        ['caddy', 'php8.5-fpm'],
        ['orbit:gateway-https'],
    ],
    'VPN' => [
        RoleName::Vpn,
        ['dnsmasq', 'openssl'],
        ['wg-quick@orbit', 'dnsmasq'],
        ['orbit:vpn-ssh'],
    ],
    'WebSocket uses the shared WireGuard baseline' => [RoleName::WebSocket, ['caddy', 'composer', 'git', 'openssl', 'php-curl', 'php-xml'], ['caddy', 'orbit-websocket'], []],
    'Router' => [
        RoleName::Router,
        ['caddy', 'openssl'],
        ['caddy'],
        [],
    ],
    'app development' => [
        RoleName::AppDev,
        ['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'php-curl', 'php-xml', 'unzip'],
        ['caddy', 'docker'],
        [],
    ],
    'app production' => [
        RoleName::AppProd,
        ['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'php-curl', 'php-xml', 'unzip'],
        ['caddy', 'docker'],
        [],
    ],
    'Ingress needs the Caddy that serves its public sites' => [
        RoleName::Ingress,
        ['caddy'],
        ['caddy'],
        [],
    ],
]);

it('checks the excluded private DNS route only when the Gateway has a VPN DNS address', function (string $probe, ?bool $expected): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.44.0.1', domain: 'orbit');
    $agents = role_runtime_agents(role_inspector_ufw(['orbit:gateway-https']));
    $ssh = new RoleInspectorSshExecutor([role_inspector_result($probe)]);

    $state = role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment(RoleName::Gateway));

    expect($state->privateDnsRouteMatches)->toBe($expected);
    expect($ssh->calls)->toHaveCount(1);
    expect($ssh->calls[0]['command'])->toEqual(new GatewayPrivateDnsResolver()->inspectCommand('10.44.0.1', 'orbit'));
    expect($ssh->calls[0]['connection'])->toEqual(new SshConnection('10.44.0.2', 'nckrtl', 22, '/key', '/known', commandTimeout: 30.0));
})->with([["1\n", true], ["0\n", false]]);

it('refuses an unreadable private DNS observation', function (): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.44.0.1', domain: 'orbit');
    $agents = role_runtime_agents(role_inspector_ufw(['orbit:gateway-https']));
    $ssh = new RoleInspectorSshExecutor([role_inspector_result("maybe\n")]);

    expect(fn () => role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment(RoleName::Gateway)))->toThrow(DoctorInspectionException::class);
});

it('leaves private DNS unchecked without a VPN DNS Node', function (): void {
    $agents = role_runtime_agents(role_inspector_ufw(['orbit:gateway-https']));
    $ssh = new RoleInspectorSshExecutor([]);

    expect(role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment(RoleName::Gateway))->privateDnsRouteMatches)->toBeNull();
    expect($ssh->calls)->toBe([]);
});

it('keeps independent package service and firewall observations', function (bool $packages, bool $services, bool $firewall): void {
    $agents = role_runtime_agents(role_inspector_ufw($firewall ? ['orbit:gateway-https'] : []));
    $agents->answer('doctor.inspect-role-runtime', ['packages' => $packages, 'services' => $services, 'caddy_version' => null]);

    $state = role_state_inspector($agents)->inspect(role_inspector_assignment(RoleName::Gateway));

    expect([$state->packagesPresent, $state->servicesActive, $state->firewallProjectionMatches])->toBe([$packages, $services, $firewall]);
})->with([[false, true, true], [true, false, true], [true, true, false]]);

it('refuses malformed results and agent failures without changing stored role attributes', function (string $failure): void {
    $agents = role_runtime_agents('Status: active');
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-role-runtime', 'doctor.role_inspection_failed'),
        'transport' => $agents->answer('doctor.inspect-role-runtime', fn () => throw new RuntimeException('secret detail')),
        'malformed' => $agents->answer('doctor.inspect-role-runtime', ['packages' => '1', 'services' => true, 'caddy_version' => null]),
        'missing version' => $agents->answer('doctor.inspect-role-runtime', ['packages' => true, 'services' => true]),
        'firewall' => $agents->answer('firewall.status', []),
    };
    $assignment = role_inspector_assignment(RoleName::Gateway);
    $before = $assignment->getAttributes();

    expect(fn () => role_state_inspector($agents)->inspect($assignment))->toThrow(DoctorInspectionException::class, '');
    expect($assignment->getAttributes())->toBe($before);
})->with(['refusal', 'transport', 'malformed', 'missing version', 'firewall']);

function role_runtime_agents(string $firewall): FakeAgentCommandRunner
{
    return new FakeAgentCommandRunner()
        ->answer('doctor.inspect-role-runtime', static fn (array $input): array => [
            'packages' => true, 'services' => true,
            'caddy_version' => in_array('caddy', $input['packages'], true) ? 'v2.11.4' : null,
        ])->answer('firewall.status', ['status' => $firewall, 'stored' => []]);
}

function role_state_inspector(FakeAgentCommandRunner $agents, ?RoleInspectorSshExecutor $ssh = null, string $ciOutcome = 'matches'): NativeRoleStateInspector
{
    $agents->answer('ci-runners.host', ['outcome' => $ciOutcome]);

    return new NativeRoleStateInspector(
        $ssh ?? new RoleInspectorSshExecutor([]), new RoleInspectorKeys, new RoleInspectorKnownHosts,
        new NodeBootstrapPackageCatalog, new NodeRoleServiceCatalog, new NodeFirewallRuleCatalog,
        new CommandDeadline, $agents, ciRunner: new CiRunnerHostProgram($agents),
    );
}

it('reports ineligible or unreadable Router LAN rules even with no desired members', function (string $family, string $protocol, string $port, bool $broad, bool $empty): void {
    [$router, $member] = router_lan_topology();
    if ($empty) {
        $member->update(['cluster_id' => null]);
    }
    $lines = ['Status: active'];
    if (! $empty) {
        foreach ([['https', 'tcp', '443'], ['dns-udp', 'udp', '53'], ['dns-tcp', 'tcp', '53']] as $index => [$name, $proto, $target]) {
            $lines[] = '[ '.($index + 1)."] 10.20.0.10 {$target}/{$proto} ALLOW IN 10.20.0.11 # orbit:router-lan-{$name}:{$member->id}";
        }
    }
    $target = ($broad ? '' : '10.20.0.10 ').$port.($protocol === 'any' ? '' : '/'.$protocol);
    $lines[] = "[ 9] {$target} ALLOW IN 10.20.0.99 # orbit:router-lan-{$family}:999";
    $agents = role_runtime_agents(implode("\n", $lines)."\n");
    $role = $router->roles()->firstOrFail();

    expect(role_state_inspector($agents)->inspect($role)->firewallProjectionMatches)->toBeFalse();
    expect($agents->commands())->toBe(['doctor.inspect-role-runtime', 'firewall.status']);
})->with([
    'HTTPS explicit protocol' => ['https', 'tcp', '443', false],
    'UDP explicit protocol' => ['dns-udp', 'udp', '53', false],
    'TCP explicit protocol' => ['dns-tcp', 'tcp', '53', false],
    'HTTPS port only' => ['https', 'any', '443', false],
    'UDP port only' => ['dns-udp', 'any', '53', false],
    'TCP port only' => ['dns-tcp', 'any', '53', false],
    'HTTPS broad port' => ['https', 'any', '443', true],
    'UDP broad port' => ['dns-udp', 'any', '53', true],
    'TCP broad port' => ['dns-tcp', 'any', '53', true],
    'HTTPS range' => ['https', 'any', '440:443', true],
    'UDP range' => ['dns-udp', 'any', '50:53', true],
    'TCP range' => ['dns-tcp', 'any', '50:53', true],
    'HTTPS unreadable' => ['https', 'any', 'OpenSSH', true],
    'UDP unreadable' => ['dns-udp', 'any', 'OpenSSH', true],
    'TCP unreadable' => ['dns-tcp', 'any', 'OpenSSH', true],
])->with(['populated desired' => false, 'empty desired' => true]);

it('accepts the exact Router families and ignores other rules but rejects IPv6 residue', function (bool $ipv6): void {
    [$router, $member] = router_lan_topology();
    $output = "Status: active\n";
    foreach ([['https', 'tcp', '443'], ['dns-udp', 'udp', '53'], ['dns-tcp', 'tcp', '53']] as $index => [$name, $proto, $port]) {
        $output .= '[ '.($index + 1)."] 10.20.0.10 {$port}/{$proto} ALLOW IN 10.20.0.11 # orbit:router-lan-{$name}:{$member->id}\n";
    }
    $output .= "[ 7] OpenSSH ALLOW IN Anywhere # orbit:router-lan-https-operator\n";
    $output .= "[ 8] 53/udp ALLOW IN 10.20.0.98 # orbit:router-lan-dns-udp-operator\n";
    if ($ipv6) {
        $output .= "[ 9] 53/udp (v6) ALLOW IN Anywhere (v6) # orbit:router-lan-dns-udp:999\n";
    }
    $agents = role_runtime_agents($output);

    expect(role_state_inspector($agents)->inspect($router->roles()->firstOrFail())->firewallProjectionMatches)->toBe(! $ipv6);
})->with([false, true]);

function role_inspector_assignment(RoleName $role): NodeRole
{
    $node = new Node([
        'name' => 'role-inspector',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'public_ssh_port' => 2022,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $node->id = 7;
    $assignment = new NodeRole([
        'node_id' => 7,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);
    $assignment->id = 11;
    $assignment->setRelation('node', $node);

    return $assignment;
}

function role_inspector_result(string $stdout): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

/** @param list<string> $comments */
function role_inspector_ufw(array $comments): string
{
    $lines = ['Status: active'];
    $number = 1;

    foreach ($comments as $comment) {
        $targets = match ($comment) {
            'orbit:vpn-ssh' => ['10.44.0.2 22/tcp on orbit'],
            'orbit:gateway-https' => ['443/tcp on orbit', '443/tcp (v6) on orbit'],
            'orbit:app-dev-http' => ['10.44.0.2 80/tcp on orbit'],
            'orbit:app-dev-https' => ['10.44.0.2 443/tcp on orbit'],
            'orbit:app-dev-direct-http' => ['80/tcp', '80/tcp (v6)'],
            'orbit:app-dev-direct-https' => ['443/tcp', '443/tcp (v6)'],
            'orbit:app-prod-http' => ['80/tcp', '80/tcp (v6)'],
            'orbit:app-prod-https' => ['443/tcp', '443/tcp (v6)'],
            default => throw new LogicException("Unknown role inspector rule [{$comment}]."),
        };

        foreach ($targets as $target) {
            $v6 = str_contains($target, '(v6)') ? ' (v6)' : '';
            $lines[] = "[ {$number}] {$target} ALLOW IN Anywhere{$v6} # {$comment}";
            $number++;
        }
    }

    return implode("\n", $lines)."\n";
}

final class RoleInspectorSshExecutor implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public bool $throws = false;

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];

        if ($this->throws) {
            throw new RuntimeException('secret transport detail');
        }

        return array_shift($this->results) ?? throw new RuntimeException('Unexpected inspector call.');
    }
}

final readonly class RoleInspectorKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/key';
    }

    public function publicKey(): string
    {
        return 'public';
    }
}

final readonly class RoleInspectorKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/known';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

it('observes the CI host through its typed inspect command', function (string $outcome, bool $matches): void {
    $agents = role_runtime_agents("Status: active\n[ 1] Anywhere ALLOW FWD Anywhere on orbitci0 # orbit-ci-runners\n[ 2] Anywhere (v6) ALLOW FWD Anywhere (v6) on orbitci0 # orbit-ci-runners\n");

    $state = role_state_inspector($agents, ciOutcome: $outcome)->inspect(role_inspector_assignment(RoleName::CiRunner));

    expect($state->ciRunnerHostMatches)->toBe($matches);
    expect($state->firewallProjectionMatches)->toBeTrue();
    expect($agents->calls[0]['input'])->toBe(['packages' => ['incus', 'incus-base', 'incus-client'], 'services' => ['incus']]);
    expect($agents->calls[2]['input'])->toBe(['action' => 'inspect']);
})->with([['matches', true], ['drift', false]]);
