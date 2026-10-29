<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Cluster;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

function networkProjectionFixture($test, string $phase = 'prune', ?string $owningKind = null): array
{
    $cluster = Cluster::query()->create(['name' => 'projection', 'state' => 'active']);
    $hub = FleetFixtures::node('projection-hub', [RoleName::Vpn]);
    $gateway = FleetFixtures::node('projection-gateway', [RoleName::Gateway]);
    $worker = FleetFixtures::node('projection-worker');
    $worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $worker->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $context = FleetFixtures::node('projection-source');
    $context->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.11', 'tld' => 'departing.test',
        'wireguard_public_key' => base64_encode(str_repeat('a', 32)), 'status' => $phase === 'prune' && $owningKind === null ? 'removing' : 'active']);
    $router = FleetFixtures::node('projection-router');
    $router->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.10', 'wireguard_public_key' => base64_encode(str_repeat('b', 32))]);
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $other = FleetFixtures::node('projection-other');
    $other->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.12', 'wireguard_public_key' => base64_encode(str_repeat('c', 32))]);
    $intent = ['key' => 'project-router', 'kind' => 'node.network_projection', 'node_id' => $router->id,
        'input' => ['context_node_id' => $context->id, 'phase' => $phase]];
    $parentKind = $owningKind ?? ($phase === 'prune' ? 'node.remove' : 'node.removal_restore');
    $definition = ['executor' => 'swamp', 'completion' => NodeWireGuardOperationCompletion::class, 'execution' => NodeLifecycleOperationExecution::class,
        'runs' => [], 'children' => [['kind' => 'node.network_projection', 'node_from' => 'intent.node_ids']],
        'completion_contract' => ['step' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'result' => ['node_plan_proven' => true]],
        'result_fields' => ['error_code']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), $parentKind => $definition]));
    $parent = Operation::query()->create(['kind' => $parentKind, 'target_type' => 'node', 'target_id' => $context->id, 'node_id' => $context->id,
        'input' => ['_node_plan' => [['type' => 'child', ...$intent, 'optional' => false]]], 'state' => ['child_intents' => [$intent]],
        'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);
    expect(app(SwampOperationExecutor::class)->claim($parentKind, 'projection-parent', $worker->id, $parent->id)?->id)->toBe($parent->id);
    $id = $test->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'projection-parent', 'key' => $intent['key']])
        ->assertAccepted()->json('data.id');
    $child = Operation::query()->findOrFail($id);
    app(OperationChildren::class)->verify($parent, $child);

    return [$parent, $child, $router, $context, $other, $hub];
}

it('captures only public per-Node network effects with exact expand and prune phases', function (string $phase): void {
    [, $child, $router, $source, $other, $hub] = networkProjectionFixture($this, $phase);
    $plan = $child->input['_node_plan'];
    expect($plan)->toHaveCount(2);
    expect($plan[0])->toMatchArray(['name' => 'firewall.router-lan', 'input' => [
        'desired' => $plan[0]['input']['desired'], 'expand' => $phase === 'expand', 'prune' => $phase === 'prune']]);
    $comments = array_column($plan[0]['input']['desired'], 'comment');
    expect(in_array('orbit:router-lan-https:'.$source->id, $comments, true))->toBe($phase === 'expand');
    expect($comments)->toContain('orbit:router-lan-https:'.$other->id);
    expect($plan[1])->toMatchArray(['name' => 'wireguard.repair-peer-dns', 'input' => [
        'dns_server' => $hub->wireguard_ip, 'public_key' => $router->wireguard_public_key, 'domains' => $plan[1]['input']['domains']]]);
    expect(in_array('departing.test', $plan[1]['input']['domains'], true))->toBe($phase === 'expand');
    expect(json_encode($child->input))->not->toContain('private_key', '$secret');
})->with(['expand', 'prune']);

it('prunes stale enrollment policy while retaining the active Node LAN contact and DNS domain', function (string $kind): void {
    [, $child, , $source] = networkProjectionFixture($this, 'prune', $kind);
    $plan = $child->input['_node_plan'];

    expect($plan[0]['input']['expand'])->toBeFalse();
    expect($plan[0]['input']['prune'])->toBeTrue();
    expect(array_column($plan[0]['input']['desired'], 'comment'))->toContain('orbit:router-lan-https:'.$source->id);
    expect($plan[1]['input']['domains'])->toContain('departing.test');
    expect($source->fresh()->status->value)->toBe('active');
})->with(['node.add', 'node.enrollment_restore']);

it('binds every network payload to its original snapshot and targets only its serving Node', function (): void {
    [$parent, $child, $router, $source] = networkProjectionFixture($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('firewall.router-lan', ['matches' => true]);
    $agents->answer('wireguard.repair-peer-dns', []);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.network_projection', 'worker' => 'projection-child', 'operation_id' => $child->id])->assertOk();
    $base = ['worker' => 'projection-child', 'transport' => 'agent'];
    $first = $child->input['_node_plan'][0];
    $second = $child->input['_node_plan'][1];
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/run', [...$base, 'key' => $second['key'], 'name' => $second['name'], 'input' => $second['input']])->assertUnprocessable();
    $changed = $first['input'];
    $changed['prune'] = false;
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/run', [...$base, 'key' => $first['key'], 'name' => $first['name'], 'input' => $changed])->assertUnprocessable();
    foreach ([$first, $second] as $step) {
        $this->postJson('/api/v1/operation-executor/'.$child->id.'/run', [...$base, 'key' => $step['key'], 'name' => $step['name'], 'input' => $step['input']])->assertOk();
    }
    $result = app(NodeLifecycleOperationExecution::class)->result($child->fresh());
    expect($result)->toBe(['node_plan_proven' => true, 'projected' => true]);
    expect(array_column($agents->calls, 'node'))->toBe([$router->name, $router->name]);
    expect($source->fresh()->status->value)->toBe('removing');
    app(OperationChildren::class)->verify($parent, $child->fresh());
});

it('refuses a projection result until native firewall matching is proven', function (): void {
    [, $child] = networkProjectionFixture($this);
    $step = $child->input['_node_plan'][0];
    $child->update(['state' => [...$child->state, 'executor_runs' => [$step['key'] => [
        'name' => $step['name'], 'transport' => 'agent', 'input' => $step['input'], 'result' => ['matches' => false]]]]]);

    expect(fn () => app(NodeLifecycleOperationExecution::class)->result($child))->toThrow(ResourceOperationException::class);
});
