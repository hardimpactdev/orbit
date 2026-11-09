<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

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

it('keeps prepared task host DHCP and DNS owned independently of role add and removal', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'task-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.3',
        'public_ssh_host' => '10.44.0.3', 'user' => 'orbit',
        'settings' => ['task_vms' => ['network' => 'proofbr0', 'cidr' => '10.252.42.0/24', 'task_places' => 2]],
    ]));
    $catalog = app(NodeFirewallRuleCatalog::class);
    $input = $catalog->taskHostInput($node);
    expect(array_map(fn ($rule) => $rule->shape->comment, $input))->toBe($catalog::TaskHostInputComments);
    foreach ($input as $rule) {
        expect($rule->shape->inInterface)->toBe('proofbr0');
    }
    expect($input[0]->shape->sourcePort)->toBe('68')
        ->and($input[1]->shape->destination)->toBe('10.252.42.1')
        ->and($input[2]->shape->destination)->toBe('10.252.42.1');
    $node->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    $desired = app(NodeFirewallDesiredRules::class)->forNode($node);
    foreach ($catalog::TaskHostInputComments as $comment) {
        $owned = array_values(array_filter($desired, fn ($rule) => $rule->name === $comment));
        expect($owned)->toHaveCount(1)->and($owned[0]->role)->toBeNull();
    }
    $roles = app(NodeRoleFirewallInput::class);
    $commands = $roles->convergeCommands($node, RoleName::CiRunner);
    foreach ([...$commands['desired'], ...$commands['remove']] as $command) {
        expect(in_array(end($command), $catalog::TaskHostInputComments, true))->toBeFalse();
    }
    foreach ($roles->remove($node, RoleName::CiRunner)['remove'] as $rule) {
        expect(in_array($rule['comment'], $catalog::TaskHostInputComments, true))->toBeFalse();
    }
});

it('gives the container bridge of a host its own DHCP and DNS input rules, apart from the VM bridge', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'container-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.3',
        'public_ssh_host' => '10.44.0.3', 'user' => 'orbit',
        'settings' => ['task_vms' => ['cidr' => '10.252.42.0/24', 'task_places' => 2,
            'containers' => ['cidr' => '10.252.43.0/24', 'task_places' => 1, 'ci_places' => 1]]],
    ]));
    $catalog = app(NodeFirewallRuleCatalog::class);
    $input = $catalog->taskHostInput($node);

    expect(array_map(fn ($rule) => $rule->shape->comment, $input))->toBe([...$catalog::TaskHostInputComments, ...$catalog::TaskContainerInputComments]);
    [, , , $dhcp, $tcp, $udp] = $input;
    expect([$dhcp->shape->inInterface, $tcp->shape->inInterface, $udp->shape->inInterface])->toBe(['orbittaskc0', 'orbittaskc0', 'orbittaskc0'])
        ->and([$dhcp->shape->sourcePort, $dhcp->shape->port, $dhcp->shape->protocol])->toBe(['68', '67', 'udp'])
        ->and([$tcp->shape->destination, $tcp->shape->port, $tcp->shape->protocol])->toBe(['10.252.43.1', '53', 'tcp'])
        ->and([$udp->shape->destination, $udp->shape->protocol])->toBe(['10.252.43.1', 'udp'])
        ->and($tcp->arguments[array_key_last($tcp->arguments)])->toBe('orbit:task-containers-dns-tcp');
    foreach (array_slice($input, 0, 3) as $rule) {
        expect($rule->shape->inInterface)->toBe('orbittask0');
    }
    $desired = app(NodeFirewallDesiredRules::class)->forNode($node);
    foreach ($catalog::TaskContainerInputComments as $comment) {
        expect(array_values(array_filter($desired, fn ($rule) => $rule->name === $comment)))->toHaveCount(1);
    }
});
