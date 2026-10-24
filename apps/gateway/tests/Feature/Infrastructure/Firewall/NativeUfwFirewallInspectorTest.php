<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionShape;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use App\Models\FirewallRule;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('sends stored and synthetic shapes in one bounded inspection', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.inspect', ['backend' => 'active', 'ownerships' => ['exact', 'drift']]);

    $result = new NativeUfwFirewallInspector($agents, app(AgentContract::class))->inspect([inspector_target(), inspector_metrics_target()]);

    expect($result->backend)->toBe(FirewallBackendStatus::Active)->and($result->rules)->toBe([FirewallRuleInspectionStatus::Exact, FirewallRuleInspectionStatus::Drift]);
    expect($agents->commands())->toBe(['firewall.inspect'])->and($agents->deadlines)->toBe([30.0]);
    expect($agents->calls[0]['input'])->toBe([
        'expected' => [
            ['comment' => 'orbit:node:7:firewall:web', 'action' => 'allow', 'direction' => 'in', 'source' => 'any', 'destination' => 'any', 'port' => '443', 'protocol' => 'tcp', 'in_interface' => null, 'out_interface' => null, 'family' => null],
            ['comment' => 'orbit:metrics-node-exporter', 'action' => 'allow', 'direction' => 'in', 'source' => '10.44.0.2', 'destination' => '10.44.0.3', 'port' => '9100', 'protocol' => 'tcp', 'in_interface' => 'orbit', 'out_interface' => null, 'family' => 'v4'],
        ], 'exclusive_families' => [], 'stored' => false,
    ]);
});

it('maps inactive and absent backends without mutating intent', function (string $backend): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.inspect', ['backend' => $backend, 'ownerships' => ['missing']]);
    $rule = inspector_rule();
    $before = $rule->getAttributes();

    $result = new NativeUfwFirewallInspector($agents, app(AgentContract::class))->inspect([FirewallInspectionTarget::fromRule($rule)]);

    expect($result->backend->value)->toBe($backend)->and($result->rules)->toBe([FirewallRuleInspectionStatus::Missing]);
    expect($rule->getAttributes())->toBe($before);
})->with(['inactive', 'absent']);

it('performs fresh inspections on separate Nodes', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.inspect', ['backend' => 'active', 'ownerships' => ['missing']]);
    $inspector = new NativeUfwFirewallInspector($agents, app(AgentContract::class));
    $first = $inspector->inspect([inspector_target()]);
    $agents->answer('firewall.inspect', ['backend' => 'active', 'ownerships' => ['exact']]);
    $second = $inspector->inspect([inspector_metrics_target(8, '10.44.0.4')]);

    expect($first->rules)->toBe([FirewallRuleInspectionStatus::Missing])->and($second->rules)->toBe([FirewallRuleInspectionStatus::Exact]);
    expect(array_column($agents->calls, 'node'))->toBe(['node-7', 'node-8']);
});

it('rejects targets from different Nodes before an agent call', function (): void {
    $agents = new FakeAgentCommandRunner;

    expect(fn () => new NativeUfwFirewallInspector($agents, app(AgentContract::class))->inspect([inspector_target(), inspector_metrics_target(8)]))->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toBe([]);
});

it('refuses malformed native observations without leaking details', function (array $result): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.inspect', $result);

    expect(fn () => new NativeUfwFirewallInspector($agents, app(AgentContract::class))->inspect([inspector_target()]))->toThrow(DoctorInspectionException::class);
})->with([
    [['backend' => 'private', 'ownerships' => []]],
    [['backend' => 'active', 'ownerships' => []]],
    [['backend' => 'active', 'ownerships' => ['private']]],
    [['backend' => 'active', 'ownerships' => ['exact', 'missing']]],
]);

it('reports transport failures as unavailable inspections', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.inspect', static fn () => throw new RuntimeException('private transport failure'));

    expect(fn () => new NativeUfwFirewallInspector($agents, app(AgentContract::class))->inspect([inspector_target()]))->toThrow(DoctorInspectionException::class);
});

function inspector_rule(): FirewallRule
{
    $node = new Node(['name' => 'node-7', 'platform' => 'linux', 'user' => 'nckrtl', 'wireguard_ip' => '10.44.0.3']);
    $node->id = 7;
    $rule = new FirewallRule([
        'node_id' => 7,
        'name' => 'web',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
    ]);
    $rule->id = 11;
    $rule->setRelation('node', $node);

    return $rule;
}

function inspector_target(): FirewallInspectionTarget
{
    return FirewallInspectionTarget::fromRule(inspector_rule());
}

function inspector_metrics_target(int $nodeId = 7, string $wireguardIp = '10.44.0.3'): FirewallInspectionTarget
{
    $node = new Node(['name' => 'node-'.$nodeId, 'platform' => 'linux', 'user' => 'nckrtl', 'wireguard_ip' => $wireguardIp]);
    $node->id = $nodeId;

    return new FirewallInspectionTarget(
        node: $node,
        shape: new FirewallInspectionShape(
            comment: 'orbit:metrics-node-exporter',
            action: 'allow',
            direction: 'in',
            source: '10.44.0.2',
            destination: $wireguardIp,
            port: '9100',
            protocol: 'tcp',
            inInterface: 'orbit',
            outInterface: null,
            family: 'v4',
        ),
        resourceId: 'orbit:metrics-node-exporter',
        resourceName: 'Metrics node exporter',
    );
}
