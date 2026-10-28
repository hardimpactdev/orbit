<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;

final class ChildKitFamily implements OperationFamilyExecution, OperationFamilyChildren, OperationCompletion
{
    public function childContext(Operation $parent): array
    {
        return ['intent' => $parent->state['prepared'] ?? [], 'children' => $parent->state['child_intents'] ?? []];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => []];
    }

    public function input(Operation $operation, array $run): array
    {
        return ['removed_node_id' => $operation->input['removed_node_id'] ?? null];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

final class ChildKitAdmission implements OperationChildAdmission
{
    public int $calls = 0;

    public bool $refuse = false;

    public function admit(Operation $parent, array $intent): Operation
    {
        $this->calls++;
        $child = app(StartOperationAction::class)->execute($intent['kind'], 'node', $intent['input']['target_id'], $intent['node_id'],
            [...$intent['input'], 'parent_operation_id' => $parent->id], 'test', null);
        if ($this->refuse) {
            throw new ResourceOperationException('test.admission_refused', 'Refused by owning family.', 409);
        }
        $child->forceFill(['run_constraints' => $parent->state['child_constraints'] ?? $child->run_constraints])->save();
        return $child;
    }
}

final class ChildKitCommand implements OperationGatewayCommand
{
    public int $calls = 0;

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['removed_node_id' => ['type' => ['integer', 'null']]],
            'required' => ['removed_node_id'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $this->calls++;
        return ['backend' => 'active'];
    }
}

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->peer = $services;
    $this->adapter = new ChildKitAdmission;
    $this->command = new ChildKitCommand;
    app()->instance(ChildKitAdmission::class, $this->adapter);
    app()->instance(ChildKitCommand::class, $this->command);
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands(['test.effect' => ChildKitCommand::class]));
    $this->parentSpec = ['executor' => 'swamp', 'completion' => ChildKitFamily::class, 'execution' => ChildKitFamily::class,
        'runs' => [], 'children' => [['kind' => 'test.effect', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['children_succeeded' => ['test.effect']], 'result_fields' => ['backend', 'error_code']];
    $this->childSpec = ['executor' => 'swamp', 'completion' => ChildKitFamily::class, 'admission' => ChildKitAdmission::class,
        'runs' => [['transport' => 'gateway', 'name' => 'test.effect', 'input_fields' => ['removed_node_id']]],
        'completion_contract' => ['step' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'result' => ['backend' => 'active']],
        'result_fields' => ['backend', 'error_code']];
    $this->register = function (): void {
        app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(),
            'test.parent' => $this->parentSpec, 'test.middle' => [...$this->parentSpec, 'admission' => ChildKitAdmission::class, 'children' => [['kind' => 'test.effect', 'node_from' => 'operation.node_id']], 'completion_contract' => ['children_succeeded' => ['test.effect']]], 'test.effect' => $this->childSpec]));
    };
    ($this->register)();
    $this->parent = app(StartOperationAction::class)->execute('test.parent', 'node', $gateway->id, $gateway->id, ['removed_node_id' => null], 'test', null);
    $this->intent = ['key' => 'apply', 'kind' => 'test.effect', 'node_id' => $gateway->id,
        'input' => ['target_id' => $gateway->id, 'removed_node_id' => null]];
    $this->parent->update(['state' => ['child_intents' => [$this->intent]]]);
    $this->path = '/api/v1/operation-executor/'.$this->parent->id;
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'parent'])->assertOk()->assertJsonPath('data.id', $this->parent->id);
});

function childKitAdmit($test): Operation
{
    $id = $test->postJson($test->path.'/children', ['worker' => 'parent', 'key' => 'apply'])->assertAccepted()->json('data.id');
    return Operation::findOrFail($id);
}

function childKitFinish($test, Operation $child, string $status = 'succeeded'): void
{
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'worker' => 'child', 'operation_id' => $child->id])->assertOk()->assertJsonPath('data.id', $child->id);
    $path = '/api/v1/operation-executor/'.$child->id;
    if ($status === 'succeeded') {
        $test->postJson($path.'/run', ['worker' => 'child', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]])->assertOk();
        $test->postJson($path.'/step', ['worker' => 'child', 'key' => 'effect.done', 'name' => 'effect', 'status' => 'succeeded', 'result' => ['backend' => 'active']])->assertOk();
    }
    $test->postJson($path.'/complete', ['worker' => 'child', 'status' => $status, 'result' => ['backend' => 'active'], 'error_code' => $status === 'failed' ? 'test.failed' : null])->assertOk();
}

it('admits through the owning family once and proves the recorded enriched child', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'apply', ...array_diff_key($this->intent, ['key' => true])])
        ->assertOk()->assertJsonPath('data.id', $child->id)->assertJsonPath('data.parent_operation_id', $this->parent->id)->assertJsonPath('data.child_key', 'apply');
    expect($this->adapter->calls)->toBe(1)->and($child->input['parent_operation_id'])->toBe($this->parent->id)
        ->and($child->lock_key)->toBeNull()->and($this->parent->refresh()->lock_key)->not->toBeNull();
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded'])->assertConflict()->assertJsonPath('error.code', 'operation.children_running');
    childKitFinish($this, $child);
    expect(app(OperationChildren::class)->requireSucceeded($this->parent, 'apply')->id)->toBe($child->id);
    $this->getJson($this->path.'/children')->assertOk()->assertJsonPath('data.0.result.backend', 'active');
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded'])->assertOk();
    expect($this->parent->refresh()->lock_key)->toBeNull();
});

it('refuses free child intent, ineligible keys, wrong relations and missing bindings', function (string $case): void {
    $body = ['worker' => 'parent', 'key' => 'apply'];
    if (in_array($case, ['input', 'kind', 'node'], true)) {
        $body += match ($case) { 'input' => ['input' => ['target_id' => 999]], 'kind' => ['kind' => 'gateway.switch_release'], 'node' => ['node_id' => $this->peer->id] };
    } elseif ($case === 'key') {
        $body['key'] = 'unprepared';
    } elseif ($case === 'relation') {
        $this->parentSpec['children'][0]['node_from'] = 'input.missing_node';
        ($this->register)();
    } else {
        $this->parentSpec['children'][0]['bind'] = ['removed_node_id' => 'input.missing_value'];
        ($this->register)();
    }
    $this->postJson($this->path.'/children', $body)->assertStatus(in_array($case, ['input', 'kind', 'node'], true) ? 409 : 422);
    expect($this->adapter->calls)->toBe(0)->and($this->parent->children()->count())->toBe(0);
})->with(['input', 'kind', 'node', 'key', 'relation', 'binding']);

it('binds nested child input without converting null or scalar types', function (): void {
    $this->parentSpec['children'][0]['node_from'] = 'intent.nodes';
    $this->parentSpec['children'][0]['bind'] = ['target_id' => 'intent.target', 'removed_node_id' => 'input.removed_node_id'];
    ($this->register)();
    $state = $this->parent->refresh()->state;
    $this->parent->update(['state' => [...$state, 'prepared' => ['nodes' => [$this->parent->node_id], 'target' => $this->parent->target_id]]]);
    expect(childKitAdmit($this)->input['removed_node_id'])->toBeNull();
});

it('rolls back refused owning admission and permits a later clean admission', function (): void {
    $this->adapter->refuse = true;
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'apply'])->assertConflict();
    expect($this->parent->children()->count())->toBe(0);
    $this->adapter->refuse = false;
    childKitAdmit($this);
    expect($this->adapter->calls)->toBe(2);
});

it('keeps same-target ancestor hold through a child chain and refuses unrelated starts', function (): void {
    $this->parentSpec['children'][0]['kind'] = 'test.middle';
    $this->parentSpec['completion_contract']['children_succeeded'] = ['test.middle'];
    ($this->register)();
    $state = $this->parent->refresh()->state;
    $this->parent->update(['state' => [...$state, 'child_intents' => [[...$this->intent, 'kind' => 'test.middle']]]]);
    $middle = childKitAdmit($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.middle', 'worker' => 'middle'])->assertOk();
    $middle->update(['state' => [...$middle->refresh()->state, 'child_intents' => [$this->intent]]]);
    $id = $this->postJson('/api/v1/operation-executor/'.$middle->id.'/children', ['worker' => 'middle', 'key' => 'apply'])->assertAccepted()->json('data.id');
    $leaf = Operation::findOrFail($id);
    expect($middle->refresh()->lock_key)->toBeNull()->and($leaf->lock_key)->toBeNull();
    childKitFinish($this, $leaf);
    expect(fn () => app(StartOperationAction::class)->execute('test.effect', 'node', $this->parent->target_id, $this->parent->node_id, [], 'other', null))->toThrow(ResourceOperationException::class);
    $this->postJson('/api/v1/operation-executor/'.$middle->id.'/complete', ['worker' => 'middle', 'status' => 'succeeded'])->assertOk();
    expect($this->parent->refresh()->lock_key)->not->toBeNull();
});

it('uses the normal independent target hold for a child on another target', function (): void {
    $state = $this->parent->refresh()->state;
    $this->parent->update(['state' => [...$state, 'child_intents' => [[...$this->intent, 'input' => [...$this->intent['input'], 'target_id' => $this->peer->id]]]]]);
    $child = childKitAdmit($this);
    expect($child->lock_key)->toBe('node:'.$this->peer->id);
    childKitFinish($this, $child);
    expect($child->refresh()->lock_key)->toBeNull()->and($this->parent->refresh()->lock_key)->not->toBeNull();
});

it('refuses tampered admitted input before child run and before parent proof', function (): void {
    $child = childKitAdmit($this);
    $child->update(['input' => [...$child->input, 'removed_node_id' => 99]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child'])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/run', ['worker' => 'child', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => 99]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    expect($this->command->calls)->toBe(0);
    $child->update(['status' => OperationStatus::Succeeded]);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded'])->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
});

it('durably yields the parent worker and resumes only after the exact child settles', function (string $status): void {
    $child = childKitAdmit($this);
    $wait = ['worker' => 'parent', 'keys' => ['apply']];
    $this->postJson($this->path.'/wait', $wait)->assertOk()->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.worker', null)->assertJsonPath('data.lease_seconds', 0)->assertJsonPath('data.waiting_for.0.id', $child->id);
    $this->postJson($this->path.'/wait', $wait)->assertOk();
    expect($this->parent->refresh()->lock_key)->not->toBeNull();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'new-parent'])->assertOk()->assertJsonPath('data', null);
    $this->postJson($this->path.'/heartbeat', ['worker' => 'parent'])->assertConflict();
    childKitFinish($this, $child, $status);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'new-parent'])->assertOk()->assertJsonPath('data.id', $this->parent->id);
    $this->postJson($this->path.'/wait', $wait)->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    expect($this->parent->refresh()->state['executor_reentry']['children'][0]['id'])->toBe($child->id);
    $this->postJson($this->path.'/complete', ['worker' => 'new-parent', 'status' => 'succeeded'])->assertStatus($status === 'succeeded' ? 200 : 409);
})->with(['succeeded', 'failed']);

it('refuses foreign or changed wait evidence', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['foreign']])->assertConflict();
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    $child->forceFill(['child_fingerprint' => str_repeat('0', 64)])->save();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'new'])->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
});

it('keeps constraint inheritance immutable and enforces a time window before any effect', function (): void {
    $window = ['timezone' => 'Europe/Amsterdam', 'start' => '03:00', 'end' => '06:00'];
    $this->parent->forceFill(['run_constraints' => ['time_window' => $window]])->save();
    $child = childKitAdmit($this);
    expect($child->run_constraints)->toBe(['time_window' => $window]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child'])->assertOk();
    $run = ['worker' => 'child', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]];
    $this->travelTo(now()->setTimezone('Europe/Amsterdam')->setTime(6, 0));
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child'])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.run_constraint_failed');
    expect($this->command->calls)->toBe(0);
});

it('rejects weakened or invalid inherited constraints during admission', function (array $constraints): void {
    $this->parent->forceFill(['run_constraints' => ['time_window' => ['timezone' => 'Europe/Amsterdam', 'start' => '03:00', 'end' => '06:00']]])->save();
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_constraints' => $constraints]]);
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'apply'])->assertUnprocessable();
    expect($this->parent->children()->count())->toBe(0);
})->with([
    'weakened' => [['time_window' => ['timezone' => 'Europe/Amsterdam', 'start' => '01:00', 'end' => '23:00']]],
    'unknown' => [['unknown' => []]],
]);

it('does not accept bare missing-child success or admit after cancellation', function (): void {
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded'])->assertConflict();
    $this->parent->update(['cancel_requested_at' => now()]);
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'apply'])->assertConflict()->assertJsonPath('error.code', 'operation.cancelled');
    expect($this->adapter->calls)->toBe(0);
});

it('keeps a cancelled Swamp waiting parent held until its children settle', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    app(\App\Actions\Operations\CancelOperationAction::class)->execute($this->parent->refresh());
    expect($this->parent->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($this->parent->cancel_requested_at)->not->toBeNull()
        ->and($this->parent->lock_key)->not->toBeNull();
    childKitFinish($this, $child);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'resume'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'resume', 'status' => 'cancelled'])->assertOk();
});
