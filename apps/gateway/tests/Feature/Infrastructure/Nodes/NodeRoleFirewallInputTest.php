<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

/** The desired rules of firewallSnapshotNode() on origin/main, before the task VM settings went. */
const FirewallSnapshot = [
    [
        'orbit:wireguard-members',
        null,
        [
            'comment' => 'orbit:wireguard-members',
            'action' => 'allow',
            'direction' => 'in',
            'source' => 'any',
            'destination' => '10.44.0.3',
            'port' => 'any',
            'protocol' => 'any',
            'in_interface' => 'orbit',
            'out_interface' => null,
            'family' => 'v4',
        ],
    ],
    [
        'orbit-ci-runners',
        'ci-runner',
        [
            'comment' => 'orbit-ci-runners',
            'action' => 'allow',
            'direction' => 'fwd',
            'source' => 'any',
            'destination' => 'any',
            'port' => 'any',
            'protocol' => 'any',
            'in_interface' => 'orbitci0',
            'out_interface' => null,
            'family' => null,
        ],
    ],
    [
        'orbit:gateway-https',
        'gateway',
        [
            'comment' => 'orbit:gateway-https',
            'action' => 'allow',
            'direction' => 'in',
            'source' => 'any',
            'destination' => 'any',
            'port' => '443',
            'protocol' => 'tcp',
            'in_interface' => 'orbit',
            'out_interface' => null,
            'family' => null,
        ],
    ],
];

it('captures Grafana isolation before its Gateway allow so insertion gives the allow priority', function (): void {
    Http::preventStrayRequests();
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3', 'user' => 'orbit']);

    $commands = app(NodeRoleFirewallInput::class)->convergeCommands($metrics, RoleName::Metrics);

    expect(array_slice($commands['desired'], -2))->toBe([
        ['ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.3', 'port', '3000', 'comment', 'orbit:metrics-grafana-isolation'],
        ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.1', 'to', '10.44.0.3', 'port', '3000', 'comment', 'orbit:metrics-grafana-upstream'],
    ]);
    Http::assertNothingSent();
});

it('refuses a Metrics firewall intent without one active Gateway', function (): void {
    $metrics = new Node(['wireguard_ip' => '10.44.0.3', 'user' => 'orbit']);

    expect(fn () => app(NodeRoleFirewallInput::class)->convergeCommands($metrics, RoleName::Metrics))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('metrics.gateway_ambiguous'));
});

/** A Node with the app-dev, gateway and ci-runner roles, WireGuard, LAN and Instance bridge addresses and the given stored settings. */
function firewallSnapshotNode(?array $settings = null, string $name = 'app-dev', string $ip = '10.44.0.3'): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'lan_ip' => '192.168.1.5',
        'public_ssh_host' => $ip, 'user' => 'orbit', 'instance_bridge' => '10.253.0.0/24', 'settings' => $settings,
    ]));
    foreach ([RoleName::AppDev, RoleName::Gateway, RoleName::CiRunner] as $role) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }

    return $node;
}

it('keeps a normal Node\'s managed firewall rules as main had them', function (): void {
    $desired = app(NodeFirewallDesiredRules::class)->forNode(firewallSnapshotNode());

    expect(array_map(static fn ($rule): array => [$rule->name, $rule->role, $rule->shape->toAgentInput()], $desired))->toBe(FirewallSnapshot);
});

it('adds no rule for a task_vms key in a Node\'s settings', function (): void {
    $plain = app(NodeFirewallDesiredRules::class)->forNode(firewallSnapshotNode());
    $stored = app(NodeFirewallDesiredRules::class)->forNode(firewallSnapshotNode(['task_vms' => ['network' => 'proofbr0', 'cidr' => '10.252.42.0/24', 'task_places' => 2,
        'containers' => ['cidr' => '10.252.43.0/24', 'task_places' => 1]]], 'task-host', '10.44.0.4'));

    expect(array_map(static fn ($rule): string => $rule->name, $stored))->toBe(array_map(static fn ($rule): string => $rule->name, $plain))
        ->and($stored)->not->toBeEmpty()
        ->and(array_filter($stored, static fn ($rule): bool => str_starts_with($rule->name, 'orbit:task-')))->toBe([]);
});

it('sends the vpn Node no task VM rule to keep or to remove: its hub converge deletes the old ones', function (): void {
    $control = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.15', 'public_ssh_host' => '10.44.0.15', 'user' => 'orbit',
    ]));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $vpn = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $catalog = app(NodeFirewallRuleCatalog::class);

    $commands = app(NodeRoleFirewallInput::class)->convergeCommands($vpn, RoleName::Vpn);

    $taskVm = static fn (array $command): bool => in_array(WireGuardAddressAllocator::ReservedRange, $command, true)
        || str_starts_with((string) end($command), 'orbit:task-vm-');

    expect(array_filter($catalog->forRole($vpn, RoleName::Vpn), static fn ($rule): bool => $rule->shape->destination === WireGuardAddressAllocator::ReservedRange
        || $rule->shape->source === WireGuardAddressAllocator::ReservedRange))->toBe([])
        ->and(array_filter($commands['desired'], $taskVm))->toBe([])
        ->and(array_filter($commands['remove'], $taskVm))->toBe([]);
});

// docs/cli/firewall/index.mdx#vpn-peer-forwarding: production's hub holds this rule by hand, exactly as `ufw show added` prints it.
it('gives the vpn hub the peer forwarding rule as production\'s hand rule and changes nothing else in its converge', function (): void {
    $hub = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'node-1', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '94.237.124.160',
        'user' => 'orbit',
    ]));
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $added = "ufw route allow in on orbit out on orbit from 10.44.0.0/24 to 10.44.0.0/24 comment 'orbit:vpn-peer-forwarding'";

    $commands = app(NodeRoleFirewallInput::class)->convergeCommands($hub, RoleName::Vpn);
    $rule = app(NodeFirewallRuleCatalog::class)->vpnPeerForwarding();

    expect($commands)->toBe([
        'desired' => [
            ['ufw', 'allow', 'in', 'on', 'orbit', 'to', '10.44.0.1', 'comment', 'orbit:wireguard-members'],
            ['ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'from', '10.44.0.0/24', 'to', '10.44.0.0/24', 'comment', 'orbit:vpn-peer-forwarding'],
        ],
        'remove' => [
            ['ufw', '--force', 'delete', 'allow', 'in', 'proto', 'tcp', 'to', 'any', 'port', '22', 'comment', 'orbit:public-ssh-recovery'],
            ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'to', '10.44.0.1', 'port', '22', 'comment', 'orbit:vpn-ssh'],
        ],
    ])
        ->and(implode(' ', $commands['desired'][1]))->toBe(str_replace("'", '', $added))
        // The shape the firewall inspection reads from `ufw status` for that rule, so Doctor and `firewall:list` find it exact.
        ->and($rule->shape->toAgentInput())->toBe(['comment' => 'orbit:vpn-peer-forwarding', 'action' => 'allow', 'direction' => 'fwd',
            'source' => '10.44.0.0/24', 'destination' => '10.44.0.0/24', 'port' => 'any', 'protocol' => 'any', 'in_interface' => 'orbit',
            'out_interface' => 'orbit', 'family' => 'v4'])
        ->and($rule->removalArguments())->toBe(['ufw', '--force', 'route', 'delete', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'from',
            '10.44.0.0/24', 'to', '10.44.0.0/24', 'comment', 'orbit:vpn-peer-forwarding'])
        ->and(app(NodeFirewallRuleCatalog::class)->forRole(new Node(['wireguard_ip' => '10.44.0.2']), RoleName::AppDev))->toBe([]);
});

it('forwards within the Gateway\'s own VPN subnet, as a nested fleet\'s hub has it', function (): void {
    app(VpnSettings::class)->configure('10.45.0.0/24');

    expect(app(NodeFirewallRuleCatalog::class)->vpnPeerForwarding()->arguments)->toBe(['sudo', 'ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out',
        'on', 'orbit', 'from', '10.45.0.0/24', 'to', '10.45.0.0/24', 'comment', 'orbit:vpn-peer-forwarding']);
});

it('opens the machine bus port on the Gateway over WireGuard only once the Gateway holds the bus keys', function (): void {
    $catalog = app(NodeFirewallRuleCatalog::class);
    $comments = static fn (): array => array_map(static fn ($rule): string => $rule->shape->comment,
        $catalog->forRole(new Node(['wireguard_ip' => '10.44.0.1']), RoleName::Gateway));
    expect($comments())->toBe(['orbit:gateway-https']);

    config(['orbit.engine.nats.seed' => 'SA-test']);
    $nats = array_values(array_filter($catalog->forRole(new Node(['wireguard_ip' => '10.44.0.1']), RoleName::Gateway),
        static fn ($rule): bool => $rule->shape->comment === 'orbit:gateway-nats'));
    expect($comments())->toBe(['orbit:gateway-https', 'orbit:gateway-nats'])
        ->and($nats[0]->shape->port)->toBe('4222')
        ->and($nats[0]->shape->inInterface)->toBe('orbit');
});
