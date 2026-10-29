<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

it('uses the inherited hold and a stamped compensation to restore a cancelled removal', function (): void {
    $gateway = FleetFixtures::node('stage-gateway', [RoleName::Gateway], 'macos');
    $worker = FleetFixtures::node('stage-worker', platform: 'macos');
    $worker->accessibleNodes()->attach($gateway);
    $source = FleetFixtures::node('stage-source', platform: 'macos');
    config(['dependency_detection.services_node_id' => $worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $begin = ['key' => 'remove-begin', 'kind' => 'node.removal_begin', 'node_id' => $source->id, 'input' => []];
    $restore = ['key' => 'remove-restore', 'kind' => 'node.removal_restore', 'node_id' => $source->id, 'input' => []];
    $definition = ['executor' => 'swamp', 'execution' => NodeLifecycleOperationExecution::class, 'completion' => NodeWireGuardOperationCompletion::class,
        'runs' => [['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true]],
        'children' => [['kind' => 'node.removal_begin', 'node_from' => 'intent.node_ids'], ['kind' => 'node.removal_restore', 'node_from' => 'intent.node_ids']],
        'compensations' => ['node.removal_restore'], 'cancellation_boundary' => 'remove-begin',
        'completion_contract' => ['step' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'result' => ['node_plan_proven' => true]],
        'result_fields' => ['node_plan_proven', 'node_restore_required', 'removed', 'error_code', 'failed_step']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'node.remove' => $definition]));
    $parent = app(StartOperationAction::class)->execute('node.remove', 'node', $source->id, $source->id,
        ['caller_node_id' => $gateway->id, '_node_plan' => [['type' => 'child', ...$begin, 'optional' => false]],
            '_node_result' => ['removed' => true], '_node_failure_result' => ['removed' => false]], 'test', null);
    $parent->update(['state' => ['child_intents' => [$begin, $restore], 'removal_intent' => ['id' => $source->id, 'prior_status' => 'active', 'offline' => false]]]);
    expect(app(SwampOperationExecutor::class)->claim('node.remove', 'parent', $worker->id, $parent->id)?->id)->toBe($parent->id);
    $beginId = $this->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'parent', 'key' => 'remove-begin'])
        ->assertAccepted()->json('data.id');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.removal_begin', 'worker' => 'begin', 'operation_id' => $beginId])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$beginId.'/run', ['worker' => 'begin', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson('/api/v1/operation-executor/'.$beginId.'/run', ['worker' => 'begin', 'key' => 'remove-state', 'transport' => 'gateway', 'name' => 'node.begin-removal', 'input' => []])
        ->assertOk()->assertJsonPath('data.removing', true);
    $result = $this->postJson('/api/v1/operation-executor/'.$beginId.'/run', ['worker' => 'begin', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertOk()->assertJsonPath('data.node_plan_proven', true)->json('data');
    $this->postJson('/api/v1/operation-executor/'.$beginId.'/step', ['worker' => 'begin', 'key' => 'node-lifecycle-result', 'name' => 'node-lifecycle-result', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$beginId.'/complete', ['worker' => 'begin', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->assertDatabaseHas('nodes', ['id' => $source->id, 'status' => 'removing']);
    $parent->refresh()->update(['cancel_requested_at' => now()]);
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/run', ['worker' => 'parent', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    expect($parent->fresh()->state['executor_runs']['node-lifecycle-result']['started'])->toBeFalse();
    $this->assertDatabaseHas('nodes', ['id' => $source->id, 'status' => 'removing']);
    $restoreId = $this->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'parent', 'key' => 'remove-restore'])
        ->assertAccepted()->json('data.id');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.removal_restore', 'worker' => 'restore', 'operation_id' => $restoreId])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$restoreId.'/run', ['worker' => 'restore', 'key' => 'restore-state', 'transport' => 'gateway', 'name' => 'node.restore-removal-state', 'input' => []])
        ->assertOk()->assertJsonPath('data.restoring', true);
    $result = $this->postJson('/api/v1/operation-executor/'.$restoreId.'/run', ['worker' => 'restore', 'key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []])
        ->assertOk()->assertJsonPath('data.restored', true)->json('data');
    $this->postJson('/api/v1/operation-executor/'.$restoreId.'/step', ['worker' => 'restore', 'key' => 'node-lifecycle-result', 'name' => 'node-lifecycle-result', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$restoreId.'/complete', ['worker' => 'restore', 'status' => 'succeeded', 'result' => $result])->assertOk();
    app(OperationChildren::class)->requireSucceeded($parent->fresh(), 'remove-restore');
    $this->assertDatabaseHas('nodes', ['id' => $source->id, 'status' => 'active']);
    $this->assertDatabaseHas('operations', ['id' => $parent->id, 'lock_key' => 'node:'.$source->id]);
    expect(Operation::query()->where('id', $restoreId)->value('lock_key'))->toBeNull();
    $terminal = app(NodeLifecycleOperationExecution::class)->context($parent->fresh())['result'];
    unset($terminal['node_restore_required']);
    $this->postJson('/api/v1/operation-executor/'.$parent->id.'/complete', ['worker' => 'parent', 'status' => 'cancelled', 'result' => $terminal, 'error_code' => 'operation.cancelled'])->assertOk();
    $this->assertDatabaseHas('operations', ['id' => $parent->id, 'status' => 'cancelled', 'lock_key' => null]);
});
