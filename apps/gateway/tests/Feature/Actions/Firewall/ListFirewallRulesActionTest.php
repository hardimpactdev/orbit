<?php

declare(strict_types=1);

use App\Actions\Firewall\ListFirewallRulesAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\Node;
use App\Models\NodeFact;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function list_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
        'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

function list_rule(Node $node, string $action, string $port, string $source = 'any', ?string $label = null): void
{
    $node->firewallRules()->create([
        'action' => $action, 'port' => $port, 'protocol' => 'tcp', 'source' => $source, 'label' => $label, 'status' => LifecycleStatus::Active,
    ]);
}

describe('ListFirewallRulesAction', function (): void {
    it('lists the rules of each requested Node in UFW order and leaves out the others', function (): void {
        $dev = list_node('app-dev', '10.44.0.2');
        $prod = list_node('app-prod', '10.44.0.3');
        $other = list_node('other', '10.44.0.4');
        list_rule($dev, 'allow', '5432', label: 'office');
        list_rule($dev, 'deny', '5432', '10.99.0.0/16');
        list_rule($dev, 'allow', '5432', '10.99.1.7');
        list_rule($prod, 'deny', '25');
        list_rule($other, 'allow', '80');

        $nodes = app(ListFirewallRulesAction::class)->execute([$dev->id, $prod->id], managed: false, live: false);

        expect(array_column($nodes, 'node'))->toBe(['app-dev', 'app-prod'])
            ->and(array_map(static fn (array $rule): string => $rule['source'], $nodes[0]['rules']))->toBe(['10.99.1.7', '10.99.0.0/16', 'any'])
            ->and($nodes[0]['rules'][2]['label'])->toBe('office')
            ->and($nodes[0]['rules'][0])->not->toHaveKeys(['backend_status', 'match'])
            ->and($nodes[0])->not->toHaveKeys(['managed', 'live', 'agent'])
            ->and($nodes[1]['rules'])->toHaveCount(1);
    });

    it('adds the orbit: rules with their role when asked', function (): void {
        $node = list_node('app-dev', '10.44.0.2');

        $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: true, live: false)[0];

        expect(array_column($entry['managed'], 'name'))->toContain('orbit:wireguard-members')
            ->and($entry['managed'][0])->toHaveKeys(['name', 'role', 'action', 'source', 'port', 'protocol'])
            ->and($entry['managed'][0])->not->toHaveKey('match');
    });

    it('marks every rule unverifiable until the agent reports UFW', function (): void {
        $node = list_node('app-dev', '10.44.0.2');
        list_rule($node, 'allow', '5432');

        $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: false, live: true)[0];

        expect($entry['rules'][0]['match'])->toBe('unverifiable')
            ->and($entry['live']['backend_status'])->toBe('unverifiable')
            ->and($entry['live']['observed_at'])->toBeNull()
            ->and($entry['live']['unmatched'])->toBe([]);
    });

    it('matches the rules with the UFW report: exact, missing and a rule added by hand', function (): void {
        $node = list_node('app-dev', '10.44.0.2');
        list_rule($node, 'allow', '5432', '10.99.1.7');
        list_rule($node, 'deny', '25');
        $rule = static fn (string $action, string $port, string $source, string $comment): array => [
            'action' => $action, 'direction' => 'in', 'protocol' => 'tcp', 'port' => $port, 'source' => $source,
            'destination' => 'any', 'comment' => $comment, 'family' => 'v4', 'in_interface' => null, 'out_interface' => null,
        ];
        NodeFact::query()->create([
            'node_id' => $node->id, 'kind' => NodeFactKind::Ufw, 'digest' => 'a', 'observed_at' => now(), 'received_at' => now(),
            'data' => ['installed' => true, 'enabled' => true, 'rules' => [
                $rule('allow', '5432', '10.99.1.7', 'orbit'),
                $rule('allow', '9999', 'any', ''),
            ]],
        ]);

        $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: false, live: true)[0];
        $matches = array_column($entry['rules'], 'match', 'port');

        expect($matches)->toBe(['5432' => 'exact', '25' => 'missing'])
            ->and($entry['live']['backend_status'])->toBe('active')
            ->and($entry['live']['observed_at'])->not->toBeNull()
            ->and(array_column($entry['live']['unmatched'], 'match', 'port'))->toBe(['9999' => 'unmanaged']);
    });

    it('keeps both recorded rules exact when their label is reused on another port', function (): void {
        $node = list_node('app-dev', '10.44.0.2');
        list_rule($node, 'allow', '47869', '10.232.2.11', 'office web');
        list_rule($node, 'allow', '47870', '10.232.2.11', 'office web');
        NodeFact::query()->create([
            'node_id' => $node->id, 'kind' => NodeFactKind::Ufw, 'digest' => 'a', 'observed_at' => now(), 'received_at' => now(),
            'data' => ['installed' => true, 'enabled' => true, 'rules' => array_map(static fn (string $port): array => [
                'action' => 'allow', 'direction' => 'in', 'protocol' => 'tcp', 'port' => $port, 'source' => '10.232.2.11',
                'destination' => '0.0.0.0/0', 'comment' => 'orbit office web', 'family' => 'v4', 'in_interface' => null, 'out_interface' => null,
            ], ['47869', '47870'])],
        ]);

        $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: false, live: true)[0];

        expect(array_column($entry['rules'], 'match', 'port'))->toBe([47869 => 'exact', 47870 => 'exact'])
            ->and($entry['live']['unmatched'])->toBe([]);
    });

    it('projects same-label drift onto the recorded rule instead of calling it exact or missing', function (): void {
        $node = list_node('app-dev', '10.44.0.2');
        list_rule($node, 'allow', '5432', '10.99.1.7', 'office');
        NodeFact::query()->create([
            'node_id' => $node->id, 'kind' => NodeFactKind::Ufw, 'digest' => 'a', 'observed_at' => now(), 'received_at' => now(),
            'data' => ['installed' => true, 'enabled' => true, 'rules' => [[
                'action' => 'allow', 'direction' => 'in', 'protocol' => 'tcp', 'port' => '5432', 'source' => '10.98.0.9',
                'destination' => 'any', 'comment' => 'orbit office', 'family' => 'v4', 'in_interface' => null, 'out_interface' => null,
            ]]],
        ]);

        $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: false, live: true)[0];

        expect($entry['rules'][0]['match'])->toBe('drift');
        expect($entry['live']['unmatched'][0])->toMatchArray(['source' => '10.98.0.9', 'match' => 'drift']);
    });

    it('counts every rule as missing while UFW is off', function (): void {
        $node = list_node('app-dev', '10.44.0.2');
        list_rule($node, 'allow', '5432');
        NodeFact::query()->create([
            'node_id' => $node->id, 'kind' => NodeFactKind::Ufw, 'digest' => 'a', 'observed_at' => now(), 'received_at' => now(),
            'data' => ['installed' => true, 'enabled' => false, 'rules' => []],
        ]);

        $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: false, live: true)[0];

        expect($entry['live']['backend_status'])->toBe('inactive')
            ->and($entry['rules'][0]['match'])->toBe('missing');
    });
});

it('lists the complete task host input policy as managed and detects a widened DHCP source port', function (): void {
    $node = list_node('task-host', '10.44.0.10');
    $node->update(['settings' => ['task_vms' => ['cidr' => '10.252.42.0/24', 'task_places' => 6, 'ci_places' => 2, 'network' => 'proofbr0']]]);
    $catalog = app(NodeFirewallRuleCatalog::class)->taskHostInput($node);
    $shapes = array_map(static fn ($rule): array => $rule->shape->toAgentInput(), $catalog);
    $live = $shapes;
    $live[0]['source_port'] = 'any';
    NodeFact::query()->create([
        'node_id' => $node->id, 'kind' => NodeFactKind::Ufw, 'digest' => 'task-input', 'observed_at' => now(), 'received_at' => now(),
        'data' => ['installed' => true, 'enabled' => true, 'rules' => $live],
    ]);

    $entry = app(ListFirewallRulesAction::class)->execute([$node->id], managed: true, live: true)[0];
    $managed = array_values(array_filter($entry['managed'], static fn (array $row): bool => str_starts_with($row['name'], 'orbit:task-vms-')));

    expect(array_column($managed, 'name'))->toBe(['orbit:task-vms-dhcp', 'orbit:task-vms-dns-tcp', 'orbit:task-vms-dns-udp']);
    expect(array_column($managed, 'match', 'name'))->toBe([
        'orbit:task-vms-dhcp' => 'drift', 'orbit:task-vms-dns-tcp' => 'exact', 'orbit:task-vms-dns-udp' => 'exact',
    ]);
    expect($shapes[0])->toMatchArray(['port' => '67', 'source_port' => '68', 'protocol' => 'udp', 'in_interface' => 'proofbr0']);
    expect($shapes[1])->toMatchArray(['port' => '53', 'destination' => '10.252.42.1', 'protocol' => 'tcp', 'in_interface' => 'proofbr0']);
    expect($shapes[2])->toMatchArray(['port' => '53', 'destination' => '10.252.42.1', 'protocol' => 'udp', 'in_interface' => 'proofbr0']);
});
