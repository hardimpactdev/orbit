<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
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

it('adds no rule for a task_vms value that a Node still stores', function (): void {
    $plain = app(NodeFirewallDesiredRules::class)->forNode(firewallSnapshotNode());
    $stored = app(NodeFirewallDesiredRules::class)->forNode(firewallSnapshotNode(['task_vms' => ['network' => 'proofbr0', 'cidr' => '10.252.42.0/24', 'task_places' => 2,
        'containers' => ['cidr' => '10.252.43.0/24', 'task_places' => 1]]], 'task-host', '10.44.0.4'));

    expect(array_map(static fn ($rule): string => $rule->name, $stored))->toBe(array_map(static fn ($rule): string => $rule->name, $plain))
        ->and($stored)->not->toBeEmpty()
        ->and(array_filter($stored, static fn ($rule): bool => str_starts_with($rule->name, 'orbit:task-')))->toBe([]);
});

it('keeps no vpn rule into the reserved range, and retires the hub routes the vpn Node kept into it', function (): void {
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

    expect(array_filter($catalog->forRole($vpn, RoleName::Vpn), static fn ($rule): bool => $rule->shape->destination === WireGuardAddressAllocator::ReservedRange))->toBe([])
        ->and(array_filter($commands['desired'], static fn (array $command): bool => in_array(WireGuardAddressAllocator::ReservedRange, $command, true)))->toBe([])
        ->and(array_values(array_intersect(array_map(static fn (array $command): string => (string) end($command), $commands['remove']), $catalog::RetiredReservedRangeComments)))
        ->toBe($catalog::RetiredReservedRangeComments);
});
