<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeEnrollmentOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

/** @return array{Operation,Node,array<string,mixed>} */
function enrollmentStagesParent($test, bool $withSettings = false): array
{
    $gateway = FleetFixtures::node('enrollment-state-gateway', [RoleName::Gateway], 'macos');
    $worker = FleetFixtures::node('enrollment-state-worker', platform: 'macos');
    $worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $worker->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $node = FleetFixtures::node('enrollment-state-source');
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $prior = $node->refresh()->only(AdmitNodeEnrollmentOperationAction::IdentityFields);
    $prior['status'] = 'active';
    $candidate = $prior;
    unset($candidate['status'], $candidate['architecture'], $candidate['ssh_host_key_type'], $candidate['ssh_host_key'], $candidate['ssh_host_fingerprint'], $candidate['wireguard_public_key']);
    $candidate['user'] = 'candidate';
    $candidate['public_ssh_host'] = '192.0.2.120';
    $intents = [
        ['key' => 'add-begin', 'kind' => 'node.enrollment_begin', 'node_id' => $node->id, 'input' => []],
        ['key' => 'add-activate', 'kind' => 'node.enrollment_activate', 'node_id' => $node->id, 'input' => []],
    ];
    if ($withSettings) {
        $intents[] = ['key' => 'add-settings', 'kind' => 'node.enrollment_settings', 'node_id' => $node->id, 'input' => []];
    }
    $intents[] = ['key' => 'add-restore', 'kind' => 'node.enrollment_restore', 'node_id' => $node->id, 'input' => []];
    $definition = ['executor' => 'swamp', 'execution' => NodeLifecycleOperationExecution::class, 'completion' => NodeWireGuardOperationCompletion::class,
        'runs' => [['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true]],
        'children' => array_map(static fn (array $intent): array => ['kind' => $intent['kind'], 'node_from' => 'intent.node_ids'], $intents),
        'compensations' => ['node.enrollment_restore'], 'cancellation_boundary' => 'add-begin',
        'completion_contract' => ['step' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'result' => ['node_plan_proven' => true]],
        'result_fields' => ['node_plan_proven', 'node_restore_required', 'enrolled', 'error_code', 'failed_step']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'node.add' => $definition]));
    $plan = array_map(static fn (array $intent): array => ['type' => 'child', ...$intent, 'optional' => false], array_slice($intents, 0, -1));
    $parent = app(StartOperationAction::class)->execute('node.add', 'node', $node->id, $node->id,
        ['mode' => 'linux', '_node_plan' => $plan, '_node_result' => ['enrolled' => true], '_node_failure_result' => ['enrolled' => false]], 'test', null);
    $parent->update(['state' => ['child_intents' => $intents, 'enrollment_intent' => ['id' => $node->id, 'existed' => true, 'prior_state' => $prior,
        'candidate' => $candidate, 'route_changes' => [], 'settings_provided' => $withSettings, 'settings' => ['apps' => ['path' => '/srv/candidate-apps']]]]]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.add', 'operation_id' => $parent->id, 'worker' => 'parent'])->assertOk();

    return [$parent->refresh(), $node, $prior];
}

function runEnrollmentStateChild($test, Operation $parent, string $key, string $kind, string $command, array $expected): Operation
{
    $id = $test->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'parent', 'key' => $key])->assertAccepted()->json('data.id');
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => $kind, 'operation_id' => $id, 'worker' => $key])->assertOk();
    $test->postJson('/api/v1/operation-executor/'.$id.'/run', ['worker' => $key, 'key' => 'enrollment-state', 'transport' => 'gateway', 'name' => $command, 'input' => []])->assertOk();
    $result = $test->postJson('/api/v1/operation-executor/'.$id.'/run', ['worker' => $key, 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertOk()->json('data');
    expect($result)->toBe(['node_plan_proven' => true, ...$expected]);
    $test->postJson('/api/v1/operation-executor/'.$id.'/step', ['worker' => $key, 'key' => 'node-lifecycle-result', 'name' => 'node-lifecycle-result', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $test->postJson('/api/v1/operation-executor/'.$id.'/complete', ['worker' => $key, 'status' => 'succeeded', 'result' => $result])->assertOk();

    return Operation::query()->findOrFail($id);
}

it('keeps the prior account during provisioning and activates only after earlier child proof', function (): void {
    [$parent, $node, $prior] = enrollmentStagesParent($this);
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'parent', 'key' => 'add-activate'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
    $begin = runEnrollmentStateChild($this, $parent, 'add-begin', 'node.enrollment_begin', 'node.begin-enrollment', ['preparing' => true]);
    expect($node->fresh()->user)->toBe($prior['user']);
    expect($node->fresh()->status->value)->toBe('provisioning');
    expect($begin->lock_key)->toBeNull();
    runEnrollmentStateChild($this, $parent, 'add-activate', 'node.enrollment_activate', 'node.activate-enrollment', ['activated' => true]);
    expect($node->fresh()->user)->toBe('candidate');
    expect($node->fresh()->status->value)->toBe('active');
    expect($parent->fresh()->lock_key)->toBe('node:'.$node->id);
});

it('restores a cancelled enrollment through its compensation child without a parent effect', function (): void {
    [$parent, $node, $prior] = enrollmentStagesParent($this);
    runEnrollmentStateChild($this, $parent, 'add-begin', 'node.enrollment_begin', 'node.begin-enrollment', ['preparing' => true]);
    $parent->refresh()->update(['cancel_requested_at' => now()]);
    $heartbeat = $this->postJson('/api/v1/operation-executor/'.$parent->id.'/heartbeat', ['worker' => 'parent'])->assertOk();
    $heartbeat->assertJsonPath('data.execution_context.result.node_restore_required', true);
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/run', ['worker' => 'parent', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    $restore = runEnrollmentStateChild($this, $parent, 'add-restore', 'node.enrollment_restore', 'node.restore-enrollment-state', ['restored' => true]);
    app(OperationChildren::class)->requireSucceeded($parent->fresh(), 'add-restore');
    expect($node->fresh()->only(array_keys($prior)))->toBe([...$prior, 'status' => LifecycleStatus::Active]);
    expect($restore->lock_key)->toBeNull();
    expect($parent->fresh()->lock_key)->toBe('node:'.$node->id);
    $result = app(NodeLifecycleOperationExecution::class)->context($parent->fresh())['result'];
    unset($result['node_restore_required']);
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/complete', ['worker' => 'parent', 'status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => $result])->assertOk();
    expect($parent->fresh()->lock_key)->toBeNull();
});

it('persists recorded enrollment settings only after the exact storage preparation is proven', function (): void {
    [$parent, $node] = enrollmentStagesParent($this, true);
    runEnrollmentStateChild($this, $parent, 'add-begin', 'node.enrollment_begin', 'node.begin-enrollment', ['preparing' => true]);
    runEnrollmentStateChild($this, $parent, 'add-activate', 'node.enrollment_activate', 'node.activate-enrollment', ['activated' => true]);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('candidate', 'candidate', '/home/candidate');
        }
    });
    $agents = new FakeAgentCommandRunner;
    $agents->answer('node.prepare-storage-root', []);
    app()->instance(AgentCommandRunner::class, $agents);
    $id = $this->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'parent', 'key' => 'add-settings'])->assertAccepted()->json('data.id');
    $settings = Operation::query()->findOrFail($id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.enrollment_settings', 'operation_id' => $id, 'worker' => 'settings'])->assertOk();
    expect($node->fresh()->settings)->toBeNull();
    $this->postJson('/api/v1/operation-executor/'.$id.'/run', ['worker' => 'settings', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])->assertConflict();
    $step = $settings->input['_node_plan'][0];
    $body = ['worker' => 'settings', 'key' => $step['key'], 'transport' => 'agent', 'name' => $step['name'], 'input' => $step['input']];
    $this->postJson('/api/v1/operation-executor/'.$id.'/run', [...$body, 'input' => [...$step['input'], 'root' => '/srv/replacement']])->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/'.$id.'/run', $body)->assertOk();
    expect($node->fresh()->settings)->toBeNull();
    $result = $this->postJson('/api/v1/operation-executor/'.$id.'/run', ['worker' => 'settings', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertOk()->assertJsonPath('data.settings_applied', true)->json('data');
    $this->postJson('/api/v1/operation-executor/'.$id.'/step', ['worker' => 'settings', 'key' => 'node-lifecycle-result', 'name' => 'node-lifecycle-result', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$id.'/complete', ['worker' => 'settings', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($node->fresh()->settings)->toBe(['apps' => ['path' => '/srv/candidate-apps']]);
    expect($agents->calls[0]['input'])->toMatchArray(['root' => '/srv/candidate-apps', 'user' => 'candidate', 'group' => 'candidate', 'create_missing' => false]);
});

it('commits Node TLD Cluster and LAN placement before exposing the serving DNS footprint', function (): void {
    [$parent, $node] = enrollmentStagesParent($this);
    $cluster = Cluster::query()->create(['name' => 'Committed DNS cluster', 'state' => 'inactive']);
    $state = $parent->state;
    $state['enrollment_intent']['candidate'] = [...$state['enrollment_intent']['candidate'], 'tld' => 'committed.test', 'cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.123'];
    $serving = FleetFixtures::node('enrollment-dns-serving', [RoleName::Vpn]);
    $extra = [
        ['key' => 'add-dns-footprint-'.$serving->id, 'kind' => 'node.converge', 'node_id' => $serving->id, 'input' => ['force_artifacts' => false, 'first_visit' => false]],
        ['key' => 'add-dns-'.$serving->id, 'kind' => 'route.private_dns_publish', 'node_id' => $serving->id, 'input' => ['reason' => 'node enrollment']],
    ];
    $state['child_intents'] = [...$state['child_intents'], ...$extra];
    $parent->update(['state' => $state, 'input' => [...$parent->input, '_node_plan' => [...$parent->input['_node_plan'],
        ...array_map(static fn (array $intent): array => ['type' => 'child', ...$intent, 'optional' => false], $extra)]]]);
    $execution = app(NodeLifecycleOperationExecution::class);
    expect(array_column($execution->childContext($parent)['children'], 'key'))->not->toContain($extra[0]['key'], $extra[1]['key']);
    runEnrollmentStateChild($this, $parent, 'add-begin', 'node.enrollment_begin', 'node.begin-enrollment', ['preparing' => true]);
    expect(array_column($execution->childContext($parent->refresh())['children'], 'key'))->not->toContain($extra[0]['key'], $extra[1]['key']);

    runEnrollmentStateChild($this, $parent, 'add-activate', 'node.enrollment_activate', 'node.activate-enrollment', ['activated' => true]);

    expect($node->refresh()->only(['tld', 'cluster_id', 'lan_ip']))->toBe(['tld' => 'committed.test', 'cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.123']);
    expect($node->status)->toBe(LifecycleStatus::Active);
    expect(array_column($execution->childContext($parent->refresh())['children'], 'key'))->toContain($extra[0]['key'])->not->toContain($extra[1]['key']);
});
