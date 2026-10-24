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
    $agents = role_runtime_agents(true);
    $ssh = new RoleInspectorSshExecutor([]);

    $state = role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment($role));

    expect($state->packagesPresent)->toBeTrue();
    expect($state->servicesActive)->toBeTrue();
    expect($state->firewallProjectionMatches)->toBeTrue();
    expect($state->caddyVersion)->toBe(in_array('caddy', $packages, true) ? 'v2.11.4' : null);
    expect($agents->calls[0]['input'])->toBe(['packages' => $packages, 'services' => $services]);
    expect(array_column($agents->calls[1]['input']['expected'], 'comment'))->toBe($comments);
    expect($agents->calls[1]['input']['stored'])->toBeFalse();
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
        [],
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
    $agents = role_runtime_agents(true);
    $ssh = new RoleInspectorSshExecutor([role_inspector_result($probe)]);

    $state = role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment(RoleName::Gateway));

    expect($state->privateDnsRouteMatches)->toBe($expected);
    expect($ssh->calls)->toHaveCount(1);
    expect($ssh->calls[0]['command'])->toEqual(new GatewayPrivateDnsResolver()->inspectCommand('10.44.0.1', 'orbit'));
    expect($ssh->calls[0]['connection'])->toEqual(new SshConnection('10.44.0.2', 'nckrtl', 22, '/key', '/known', commandTimeout: 30.0));
})->with([["1\n", true], ["0\n", false]]);

it('refuses an unreadable private DNS observation', function (): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.44.0.1', domain: 'orbit');
    $agents = role_runtime_agents(true);
    $ssh = new RoleInspectorSshExecutor([role_inspector_result("maybe\n")]);

    expect(fn () => role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment(RoleName::Gateway)))->toThrow(DoctorInspectionException::class);
});

it('leaves private DNS unchecked without a VPN DNS Node', function (): void {
    $agents = role_runtime_agents(true);
    $ssh = new RoleInspectorSshExecutor([]);

    expect(role_state_inspector($agents, $ssh)->inspect(role_inspector_assignment(RoleName::Gateway))->privateDnsRouteMatches)->toBeNull();
    expect($ssh->calls)->toBe([]);
});

it('keeps independent package service and firewall observations', function (bool $packages, bool $services, bool $firewall): void {
    $agents = role_runtime_agents($firewall);
    $agents->answer('doctor.inspect-role-runtime', ['packages' => $packages, 'services' => $services, 'caddy_version' => null]);

    $state = role_state_inspector($agents)->inspect(role_inspector_assignment(RoleName::Gateway));

    expect([$state->packagesPresent, $state->servicesActive, $state->firewallProjectionMatches])->toBe([$packages, $services, $firewall]);
})->with([[false, true, true], [true, false, true], [true, true, false]]);

it('refuses malformed results and agent failures without changing stored role attributes', function (string $failure): void {
    $agents = role_runtime_agents(true);
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-role-runtime', 'doctor.role_inspection_failed'),
        'transport' => $agents->answer('doctor.inspect-role-runtime', fn () => throw new RuntimeException('secret detail')),
        'malformed' => $agents->answer('doctor.inspect-role-runtime', ['packages' => '1', 'services' => true, 'caddy_version' => null]),
        'missing version' => $agents->answer('doctor.inspect-role-runtime', ['packages' => true, 'services' => true]),
        'firewall' => $agents->answer('firewall.inspect', []),
    };
    $assignment = role_inspector_assignment(RoleName::Gateway);
    $before = $assignment->getAttributes();

    expect(fn () => role_state_inspector($agents)->inspect($assignment))->toThrow(DoctorInspectionException::class, '');
    expect($assignment->getAttributes())->toBe($before);
})->with(['refusal', 'transport', 'malformed', 'missing version', 'firewall']);

function role_runtime_agents(bool $firewall): FakeAgentCommandRunner
{
    return new FakeAgentCommandRunner()
        ->answer('doctor.inspect-role-runtime', static fn (array $input): array => [
            'packages' => true, 'services' => true,
            'caddy_version' => in_array('caddy', $input['packages'], true) ? 'v2.11.4' : null,
        ])->answer('firewall.inspect', ['matches' => $firewall]);
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

it('sends exclusive Router families even when no members are desired', function (bool $empty, bool $matches): void {
    [$router, $member] = router_lan_topology();
    if ($empty) {
        $member->update(['cluster_id' => null]);
    }
    $agents = role_runtime_agents($matches);

    expect(role_state_inspector($agents)->inspect($router->roles()->firstOrFail())->firewallProjectionMatches)->toBe($matches);
    expect($agents->calls[1]['input']['exclusive_families'])->toBe(['orbit:router-lan-https', 'orbit:router-lan-dns-udp', 'orbit:router-lan-dns-tcp']);
    expect($agents->calls[1]['input']['expected'])->toHaveCount($empty ? 0 : 3);
})->with([false, true])->with([false, true]);

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
    $agents = role_runtime_agents(true);

    $state = role_state_inspector($agents, ciOutcome: $outcome)->inspect(role_inspector_assignment(RoleName::CiRunner));

    expect($state->ciRunnerHostMatches)->toBe($matches);
    expect($state->firewallProjectionMatches)->toBeTrue();
    expect($agents->calls[0]['input'])->toBe(['packages' => ['incus', 'incus-base', 'incus-client'], 'services' => ['incus']]);
    expect($agents->calls[2]['input'])->toBe(['action' => 'inspect']);
})->with([['matches', true], ['drift', false]]);
