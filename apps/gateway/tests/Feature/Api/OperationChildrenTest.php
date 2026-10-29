<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationCancelGuard;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRunConstraint;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationTimeWindow;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final class ChildKitFamily implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function childContext(Operation $parent): array
    {
        return ['intent' => $parent->state['prepared'] ?? [], 'children' => $parent->state['child_intents'] ?? []];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => $operation->input['descriptors'] ?? []];
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
        return $operation->input['cancellation_boundary'] ?? null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

final class ChildKitCancelGuard implements OperationCancelGuard
{
    public array $seen = [];

    public function assertCancellable(Operation $operation): void
    {
        $this->seen[$operation->id] = ['transaction' => DB::transactionLevel(),
            'cancelled' => Operation::query()->whereNotNull('cancel_requested_at')->count()];
        if ($operation->steps()->where('name', 'transfer-cutover')->where('status', 'succeeded')->exists()) {
            throw new ResourceOperationException('operation.cancel_refused', 'The cutover has succeeded.', 409);
        }
    }
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
            'test.parent' => $this->parentSpec, 'test.middle' => [...array_diff_key($this->parentSpec, ['compensations' => true, 'cancellation_boundary' => true]), 'admission' => ChildKitAdmission::class, 'children' => [['kind' => 'test.effect', 'node_from' => 'operation.node_id']], 'completion_contract' => ['children_succeeded' => ['test.effect']]], 'test.effect' => $this->childSpec, 'test.restore' => $this->childSpec, 'test.cleanup' => $this->childSpec]));
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
        $body += match ($case) {
            'input' => ['input' => ['target_id' => 999]], 'kind' => ['kind' => 'gateway.switch_release'], 'node' => ['node_id' => $this->peer->id]
        };
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
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child'])->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
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
    expect($this->parent->refresh()->state['executor_reentry']['children'][0]['id'])->toBe($child->id)
        ->and($this->parent->state['executor_reentry']['children'][0]['status'])->toBe($status)
        ->and($this->parent->state['executor_reentry']['children'][0]['result'])->toBe($child->refresh()->result);
    $this->postJson($this->path.'/complete', ['worker' => 'new-parent', 'status' => 'succeeded'])->assertStatus($status === 'succeeded' ? 200 : 409);
})->with(['succeeded', 'failed']);

it('refuses foreign or changed wait evidence', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['foreign']])->assertConflict();
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    $child->forceFill(['child_fingerprint' => str_repeat('0', 64)])->save();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'new'])->assertOk()->assertJsonPath('data', null);
    expect($this->parent->refresh()->state['executor_assistance']['error_code'])->toBe('operation.child_unproven');
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
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect($this->parent->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($this->parent->cancel_requested_at)->not->toBeNull()
        ->and($this->parent->lock_key)->not->toBeNull();
    expect($child->refresh()->status)->toBe(OperationStatus::Cancelled);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'resume'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'resume', 'status' => 'cancelled'])->assertOk();
});

it('checks named window boundaries and midnight crossing', function (string $time, string $start, string $end, bool $allowed): void {
    $this->travelTo(now()->setTimezone('Europe/Amsterdam')->setTimeFromTimeString($time));
    $check = new OperationTimeWindow;
    $invoke = fn () => $check->assertAllowed($this->parent, ['timezone' => 'Europe/Amsterdam', 'start' => $start, 'end' => $end]);
    if ($allowed) {
        $invoke();
        expect(true)->toBeTrue();
    } else {
        expect($invoke)->toThrow(ResourceOperationException::class);
    }
})->with([
    ['03:00', '03:00', '06:00', true], ['05:59', '03:00', '06:00', true], ['06:00', '03:00', '06:00', false],
    ['23:00', '22:00', '02:00', true], ['01:59', '22:00', '02:00', true], ['02:00', '22:00', '02:00', false],
]);

it('fences child writes by authenticated executor peer and restricts child reads', function (): void {
    childKitAdmit($this);
    $other = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $other->accessibleNodes()->attach($this->parent->node_id);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->getJson($this->path.'/children')->assertForbidden();
    config(['operation_executor.worker_node_ids' => [$other->id]]);
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'apply'])->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->getJson($this->path.'/children')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a child effect after any ancestor requests cancellation', function (bool $grandparent): void {
    if ($grandparent) {
        $this->parentSpec['children'][0]['kind'] = 'test.middle';
        $this->parentSpec['completion_contract']['children_succeeded'] = ['test.middle'];
        ($this->register)();
        $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'kind' => 'test.middle']]]]);
        $middle = childKitAdmit($this);
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.middle', 'worker' => 'middle'])->assertOk();
        $middle->update(['state' => [...$middle->refresh()->state, 'child_intents' => [$this->intent]]]);
        $id = $this->postJson('/api/v1/operation-executor/'.$middle->id.'/children', ['worker' => 'middle', 'key' => 'apply'])->assertAccepted()->json('data.id');
        $child = Operation::findOrFail($id);
    } else {
        $child = childKitAdmit($this);
    }
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child', 'operation_id' => $child->id])->assertOk();
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect($child->refresh()->cancel_requested_at)->not->toBeNull();
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/run', ['worker' => 'child', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]])
        ->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/complete', ['worker' => 'child', 'status' => 'cancelled'])->assertOk();
    expect($child->refresh()->status)->toBe(OperationStatus::Cancelled)->and($this->command->calls)->toBe(0);
})->with([false, true]);

function childKitCompensations($test): void
{
    $test->parentSpec['compensations'] = ['test.restore', 'test.cleanup'];
    $test->parentSpec['cancellation_boundary'] = 'apply';
    foreach (['test.restore', 'test.cleanup'] as $kind) {
        $test->parentSpec['children'][] = ['kind' => $kind, 'node_from' => 'operation.node_id'];
    }
    ($test->register)();
    $test->parent->update(['state' => [...$test->parent->refresh()->state, 'child_intents' => [
        $test->intent, [...$test->intent, 'key' => 'restore', 'kind' => 'test.restore'], [...$test->intent, 'key' => 'cleanup', 'kind' => 'test.cleanup'],
        [...$test->intent, 'key' => 'ordinary'],
    ]]]);
}

it('admits several compensation kinds only after the stamped boundary succeeded', function (): void {
    childKitCompensations($this);
    childKitFinish($this, childKitAdmit($this));
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    foreach (['restore', 'cleanup'] as $key) {
        $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => $key])->assertAccepted()->json('data.id');
        childKitFinish($this, Operation::findOrFail($id));
    }
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'ordinary'])->assertConflict()->assertJsonPath('error.code', 'operation.cancelled');
    expect($this->command->calls)->toBe(3);
});

it('does not accept missing failed or tampered compensation boundary evidence', function (string $case): void {
    childKitCompensations($this);
    if ($case !== 'missing') {
        $child = childKitAdmit($this);
        childKitFinish($this, $child, $case === 'failed' ? 'failed' : 'succeeded');
        if ($case === 'tampered') {
            $child->forceFill(['child_fingerprint' => str_repeat('0', 64)])->save();
        }
    }
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'restore'])->assertConflict()->assertJsonPath('error.code', 'operation.cancelled');
})->with(['missing', 'failed', 'tampered']);

it('allows no further parent effect after cancellation with a child compensation contract', function (): void {
    childKitCompensations($this);
    $this->parentSpec['runs'] = $this->childSpec['runs'];
    ($this->register)();
    $this->parent->update(['input' => [...$this->parent->input, 'descriptors' => [[
        'key' => 'own', 'step' => 'own', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null], 'resumable' => false, 'required' => true,
    ]]]]);
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    $this->postJson($this->path.'/run', ['worker' => 'parent', 'key' => 'own', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]])
        ->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    expect($this->command->calls)->toBe(0);
});

it('does not let a bad waiting parent block another candidate or write during discovery', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    $child->delete();
    $other = app(StartOperationAction::class)->execute('test.parent', 'node', $this->peer->id, $this->peer->id, [], 'other', null);
    $before = $this->parent->refresh()->getAttributes();
    expect(app(OperationChildren::class)->ready($this->parent))->toBeFalse()
        ->and($this->parent->refresh()->getAttributes())->toBe($before);
    $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect($this->parent->refresh()->getAttributes())->toBe($before);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'next'])->assertOk()->assertJsonPath('data.id', $other->id);
    expect($this->parent->refresh()->state['executor_assistance']['error_code'])->toBe('operation.child_unproven');
});

it('serializes same-target sibling effects under the inherited parent hold', function (): void {
    $first = childKitAdmit($this);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent, [...$this->intent, 'key' => 'second']]]]);
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'second'])->assertAccepted()->json('data.id');
    $second = Operation::findOrFail($id);
    $children = app(OperationChildren::class);
    expect($children->executionLeaseId($first))->toBe($this->parent->id)->and($children->executionLeaseId($second))->toBe($this->parent->id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'second', 'operation_id' => $second->id])->assertOk();
    $run = ['worker' => 'second', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]];
    app(OperationAttemptLease::class)->hold($children->executionLeaseId($first), function () use ($second, $run): void {
        $this->postJson('/api/v1/operation-executor/'.$second->id.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_busy');
    });
    expect($this->command->calls)->toBe(0);
    $this->postJson('/api/v1/operation-executor/'.$second->id.'/run', $run)->assertOk();
});

final class ChildKitConstraint implements OperationRunConstraint
{
    public int $calls = 0;

    public function name(): string
    {
        return 'test_window';
    }

    public function assertAllowed(Operation $operation, array $parameters): void
    {
        $this->calls++;
    }
}

it('checks a family run constraint once at dispatch', function (): void {
    $constraint = new ChildKitConstraint;
    app()->instance(ChildKitConstraint::class, $constraint);
    app()->tag([ChildKitConstraint::class], OperationRunConstraint::class);
    $this->parent->forceFill(['run_constraints' => ['test_window' => []]])->save();
    $child = childKitAdmit($this);
    expect($constraint->calls)->toBe(0);
    childKitFinish($this, $child);
    expect($constraint->calls)->toBe(1);
});

it('discovers ready waiting parents with the same pure selector used by claim', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    childKitFinish($this, $child);
    $this->parent->update(['kind' => 'firewall.allow']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'firewall.allow' => $this->parentSpec, 'test.effect' => $this->childSpec]));
    $before = $this->parent->refresh()->getAttributes();
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(collect($response->json('data.kinds'))->firstWhere('kind', 'firewall.allow')['claimable'])->toBe(1);
    expect($this->parent->refresh()->getAttributes())->toBe($before);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'resume'])->assertOk()->assertJsonPath('data.id', $this->parent->id);
});

it('flags an unavailable queued child kind for assistance without breaking other claims', function (): void {
    childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'test.parent' => $this->parentSpec]));
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'resume'])->assertOk()->assertJsonPath('data', null);
    expect($this->parent->refresh()->state['executor_assistance']['error_code'])->toBe('operation.kind_unavailable');
});

it('permits descendants only inside a proven compensation branch', function (): void {
    $this->parentSpec['children'][] = ['kind' => 'test.middle', 'node_from' => 'operation.node_id'];
    $this->parentSpec['compensations'] = ['test.middle'];
    $this->parentSpec['cancellation_boundary'] = 'apply';
    ($this->register)();
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent, [...$this->intent, 'key' => 'restore', 'kind' => 'test.middle']]]]);
    childKitFinish($this, childKitAdmit($this));
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'restore'])->assertAccepted()->json('data.id');
    $middle = Operation::findOrFail($id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.middle', 'worker' => 'middle', 'operation_id' => $id])->assertOk();
    $middle->update(['state' => [...$middle->refresh()->state, 'child_intents' => [$this->intent]]]);
    $leaf = $this->postJson('/api/v1/operation-executor/'.$id.'/children', ['worker' => 'middle', 'key' => 'apply'])->assertAccepted()->json('data.id');
    childKitFinish($this, Operation::findOrFail($leaf));
    expect($this->command->calls)->toBe(2);
});

it('cascades cancel to queued descendants and lets running descendants settle normally', function (): void {
    $queued = childKitAdmit($this);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent, [...$this->intent, 'key' => 'running']]]]);
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'running'])->assertAccepted()->json('data.id');
    $running = Operation::findOrFail($id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child', 'operation_id' => $id])->assertOk();
    $run = ['worker' => 'child', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]];
    $childPath = '/api/v1/operation-executor/'.$id;
    $this->postJson($childPath.'/step', ['worker' => 'child', 'key' => 'start', 'name' => 'effect', 'status' => 'running'])->assertOk();
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply', 'running']])->assertOk();
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect($queued->refresh()->status)->toBe(OperationStatus::Cancelled)->and($queued->cancel_requested_at)->not->toBeNull()
        ->and($queued->error_code)->toBe('operation.cancelled')->and($running->refresh()->cancel_requested_at)->not->toBeNull();
    $this->postJson($childPath.'/heartbeat', ['worker' => 'child'])->assertOk()->assertJsonPath('data.cancel_requested', true);
    $this->postJson($childPath.'/run', $run)->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    $this->postJson($childPath.'/step', ['worker' => 'child', 'key' => 'stop', 'name' => 'effect', 'status' => 'failed', 'error_code' => 'operation.cancelled'])->assertOk();
    $this->postJson($childPath.'/complete', ['worker' => 'child', 'status' => 'cancelled'])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'resume'])->assertOk()->assertJsonPath('data.id', $this->parent->id);
    $this->postJson($this->path.'/complete', ['worker' => 'resume', 'status' => 'cancelled'])->assertOk();
    expect($this->parent->refresh()->lock_key)->toBeNull()->and($this->command->calls)->toBe(0);
});

it('honors a running child own proven cancellation boundary after an ancestor cancel', function (): void {
    $this->childSpec['execution'] = ChildKitFamily::class;
    ($this->register)();
    $boundary = ['key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'result' => ['backend' => 'active']];
    $descriptors = array_map(fn (string $key): array => ['key' => $key, 'step' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect',
        'input' => ['removed_node_id' => null], 'resumable' => false, 'required' => true], ['effect', 'after']);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'input' => [...$this->intent['input'], 'descriptors' => $descriptors, 'cancellation_boundary' => $boundary]]]]]);
    $child = childKitAdmit($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child'])->assertOk();
    $path = '/api/v1/operation-executor/'.$child->id;
    $run = ['worker' => 'child', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.effect', 'input' => ['removed_node_id' => null]];
    $this->postJson($path.'/run', $run)->assertOk();
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    $this->postJson($path.'/heartbeat', ['worker' => 'child'])->assertOk()->assertJsonPath('data.cancel_requested', true);
    $this->postJson($path.'/run', [...$run, 'key' => 'after'])->assertOk()->assertJsonPath('data.backend', 'active');
    $this->postJson($path.'/step', ['worker' => 'child', 'key' => 'done', 'name' => 'effect', 'status' => 'succeeded', 'result' => ['backend' => 'active']])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'child', 'status' => 'succeeded', 'result' => ['backend' => 'active']])->assertOk();
    expect($this->command->calls)->toBe(2);
});

it('allows a cancelled intermediate transfer to restore after a grandparent cancel', function (bool $middleFirst): void {
    $this->parentSpec['children'][0]['kind'] = 'test.middle';
    $this->parentSpec['completion_contract']['children_succeeded'] = ['test.middle'];
    ($this->register)();
    $definitions = [...OperationKinds::definitions(), 'test.parent' => $this->parentSpec,
        'test.middle' => [...$this->parentSpec, 'admission' => ChildKitAdmission::class, 'children' => [
            ['kind' => 'test.effect', 'node_from' => 'operation.node_id'], ['kind' => 'test.restore', 'node_from' => 'operation.node_id'],
        ], 'compensations' => ['test.restore'], 'cancellation_boundary' => 'stop-source', 'completion_contract' => ['children_succeeded' => ['test.effect']]],
        'test.effect' => $this->childSpec, 'test.restore' => $this->childSpec];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'kind' => 'test.middle']]]]);
    $middle = childKitAdmit($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.middle', 'worker' => 'middle'])->assertOk();
    $middle->update(['state' => [...$middle->refresh()->state, 'child_intents' => [
        [...$this->intent, 'key' => 'stop-source'], [...$this->intent, 'key' => 'restore', 'kind' => 'test.restore'],
    ]]]);
    $middlePath = '/api/v1/operation-executor/'.$middle->id;
    $stop = $this->postJson($middlePath.'/children', ['worker' => 'middle', 'key' => 'stop-source'])->assertAccepted()->json('data.id');
    childKitFinish($this, Operation::findOrFail($stop));
    app(CancelOperationAction::class)->execute($middleFirst ? $middle->refresh() : $this->parent->refresh());
    expect($middle->refresh()->cancel_requested_at)->not->toBeNull();
    $restore = $this->postJson($middlePath.'/children', ['worker' => 'middle', 'key' => 'restore'])->assertAccepted()->json('data.id');
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect(Operation::findOrFail($restore)->cancel_requested_at)->toBeNull();
    childKitFinish($this, Operation::findOrFail($restore));
    $this->postJson($middlePath.'/complete', ['worker' => 'middle', 'status' => 'cancelled'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'cancelled'])->assertOk();
    expect($this->parent->refresh()->lock_key)->toBeNull()->and($this->command->calls)->toBe(2);
})->with([false, true]);

it('exposes assistance safely and permits explicit cancellation when no provable child is active', function (): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    $child->forceFill(['child_fingerprint' => str_repeat('0', 64)])->save();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'resume'])->assertOk()->assertJsonPath('data', null);
    $state = $this->parent->refresh()->state;
    $state['executor_assistance']['unsafe_detail'] = 'private-must-not-return';
    $this->parent->update(['state' => $state]);
    $this->getJson('/api/v1/operations/'.$this->parent->id)->assertOk()->assertJsonPath('data.assistance', ['error_code' => 'operation.child_unproven'])->assertDontSee('private-must-not-return');
    $this->getJson('/api/v1/operations?kind=test.parent')->assertOk()->assertJsonPath('data.0.assistance', ['error_code' => 'operation.child_unproven'])->assertDontSee('private-must-not-return');
    $this->call('POST', '/api/v1/operations/'.$this->parent->id.'/cancel', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($this->parent->refresh()->lock_key)->toBeNull();
});

it('retains a flagged parent hold while a provable running child still needs to settle', function (): void {
    $child = childKitAdmit($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child'])->assertOk();
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'executor_assistance' => ['error_code' => 'operation.child_unproven']]]);
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect($this->parent->refresh()->status)->toBe(OperationStatus::Waiting)->and($this->parent->lock_key)->not->toBeNull();
    childKitFinish($this, $child, 'cancelled');
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect($this->parent->refresh()->status)->toBe(OperationStatus::Cancelled)->and($this->parent->lock_key)->toBeNull();
});

it('preserves stamped Node identity and target proof after the Node is deleted', function (): void {
    $node = Node::query()->create(['name' => 'removed', 'public_ssh_host' => '192.0.2.55', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.55', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->parentSpec['children'][0]['node_from'] = 'input.child_node_id';
    ($this->register)();
    $this->parent->update(['input' => [...$this->parent->input, 'child_node_id' => $node->id],
        'state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'node_id' => $node->id, 'input' => [...$this->intent['input'], 'target_id' => $node->id]]]]]);
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $nodeId = $node->id;
    $node->delete();
    expect($child->refresh()->node_id)->toBeNull()->and($child->child_admission['admitted']['node_id'])->toBe($nodeId);
    expect(app(OperationChildren::class)->requireSucceeded($this->parent, 'apply')->id)->toBe($child->id);
    $this->getJson($this->path.'/children')->assertOk()->assertJsonPath('data.0.node_id', $nodeId)->assertJsonPath('data.0.result.backend', 'active');
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'apply'])->assertOk()->assertJsonPath('data.id', $child->id)->assertJsonPath('data.node_id', $nodeId);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded'])->assertOk();
});

it('refuses a changed live Node foreign key while the stamped Node still exists', function (): void {
    $child = childKitAdmit($this);
    $child->update(['node_id' => null]);
    expect(fn () => app(OperationChildren::class)->requireSucceeded($this->parent, 'apply'))->toThrow(ResourceOperationException::class);
});

it('keeps serving-node child identities when another same-target Node is removed', function (): void {
    $removed = Node::query()->create(['name' => 'old', 'public_ssh_host' => '192.0.2.50', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.50', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->parentSpec['children'][0]['node_from'] = 'input.child_nodes';
    ($this->register)();
    $this->parent->update(['input' => [...$this->parent->input, 'child_nodes' => [$removed->id, $this->parent->node_id]],
        'state' => [...$this->parent->refresh()->state, 'child_intents' => [
            [...$this->intent, 'node_id' => $removed->id, 'input' => [...$this->intent['input'], 'target_id' => $removed->id]],
            [...$this->intent, 'key' => 'residue'],
        ]]]);
    $sameTarget = childKitAdmit($this);
    childKitFinish($this, $sameTarget);
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'residue'])->assertAccepted()->json('data.id');
    $serving = Operation::findOrFail($id);
    childKitFinish($this, $serving);
    $removedId = $removed->id;
    $removed->delete();
    $receipts = app(OperationChildren::class)->receipts($this->parent);
    expect($receipts[0]['node_id'])->toBe($removedId)->and($receipts[1]['node_id'])->toBe($serving->node_id);
    expect(app(OperationChildren::class)->requireSucceeded($this->parent, 'apply')->target_id)->toBe($removedId)
        ->and(app(OperationChildren::class)->requireSucceeded($this->parent, 'residue')->target_id)->toBe($serving->target_id);
});

it('lets an enclosing parent prove its completed Node removal child after deletion', function (): void {
    $node = Node::query()->create(['name' => 'old', 'public_ssh_host' => '192.0.2.50', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.50', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->parentSpec['children'] = [['kind' => 'test.middle', 'node_from' => 'input.child_node']];
    $this->parentSpec['completion_contract']['children_succeeded'] = ['test.middle'];
    ($this->register)();
    $this->parent->update(['input' => [...$this->parent->input, 'child_node' => $node->id],
        'state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'kind' => 'test.middle', 'node_id' => $node->id, 'input' => [...$this->intent['input'], 'target_id' => $node->id]]]]]);
    $middle = childKitAdmit($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.middle', 'worker' => 'middle'])->assertOk();
    $middle->update(['state' => [...$middle->refresh()->state, 'child_intents' => [[...$this->intent, 'node_id' => $node->id, 'input' => [...$this->intent['input'], 'target_id' => $node->id]]]]]);
    $middlePath = '/api/v1/operation-executor/'.$middle->id;
    $id = $this->postJson($middlePath.'/children', ['worker' => 'middle', 'key' => 'apply'])->assertAccepted()->json('data.id');
    childKitFinish($this, Operation::findOrFail($id));
    $nodeId = $node->id;
    $node->delete();
    $completion = ['worker' => 'middle', 'status' => 'succeeded'];
    $this->postJson($middlePath.'/complete', $completion)->assertOk()->assertJsonPath('data.node_id', $nodeId);
    $this->postJson($middlePath.'/complete', $completion)->assertOk();
    expect(app(OperationChildren::class)->requireSucceeded($this->parent, 'apply')->id)->toBe($middle->id);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded'])->assertOk();
});

it('preserves newly admitted compensations across repeated ancestor cancellation', function (bool $running, bool $direct): void {
    childKitCompensations($this);
    childKitFinish($this, childKitAdmit($this));
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'restore'])->assertAccepted()->json('data.id');
    $restore = Operation::findOrFail($id);
    if ($running) {
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.restore', 'worker' => 'child', 'operation_id' => $id])->assertOk();
    }

    app(CancelOperationAction::class)->execute($this->parent->refresh());
    expect($restore->refresh()->cancel_requested_at)->toBeNull();
    expect($restore->status)->toBe($running ? OperationStatus::Running : OperationStatus::Queued);
    if ($direct) {
        app(CancelOperationAction::class)->execute($restore);
        expect($restore->refresh()->cancel_requested_at)->not->toBeNull();
        if ($running) {
            childKitFinish($this, $restore, 'cancelled');
        }
        expect($restore->refresh()->status)->toBe(OperationStatus::Cancelled);
    } else {
        childKitFinish($this, $restore);
        expect($restore->refresh()->status)->toBe(OperationStatus::Succeeded);
    }
})->with([false, true])->with([false, true]);

it('settles unprovable active descendants when abandoning a flagged waiting parent', function (string $status): void {
    $child = childKitAdmit($this);
    $this->postJson($this->path.'/wait', ['worker' => 'parent', 'keys' => ['apply']])->assertOk();
    $child->forceFill(['status' => $status, 'attempts' => 1, 'lock_key' => 'orphan-hold', 'child_fingerprint' => str_repeat('0', 64)])->save();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.parent', 'worker' => 'resume'])->assertOk()->assertJsonPath('data', null);

    app(CancelOperationAction::class)->execute($this->parent->refresh());

    expect($this->parent->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($child->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($child->lock_key)->toBeNull();
    expect($child->error_code)->toBe('operation.child_unproven');
    expect($child->state['executor_assistance']['error_code'])->toBe('operation.child_unproven');
})->with(['running', 'waiting']);

function childKitSettled($test): void
{
    $test->parentSpec['completion_contract'] = ['children_settled' => ['test.effect']];
    $test->parentSpec['result_fields'] = [
        ['name' => 'skipped', 'max' => 10, 'list_of' => ['child_id' => 'int', 'key' => 'string', 'kind' => 'string', 'reason' => 'string']],
    ];
    ($test->register)();
}

it('completes a settled parent using exact scoped child failure acknowledgements', function (string $status): void {
    childKitSettled($this);
    $child = childKitAdmit($this);
    childKitFinish($this, $child, $status);
    $result = ['skipped' => $status === 'succeeded' ? [] : [['child_id' => $child->id, 'reason' => 'Node unavailable', 'key' => 'apply', 'kind' => 'test.effect']]];
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => $result])->assertOk()->assertJsonPath('data.result', $result);
})->with(['succeeded', 'failed', 'cancelled']);

it('refuses manufactured or incomplete skipped child evidence', function (string $case): void {
    childKitSettled($this);
    $child = childKitAdmit($this);
    childKitFinish($this, $child, $case === 'successful' ? 'succeeded' : 'failed');
    $skip = ['child_id' => $child->id, 'reason' => 'Node unavailable', 'key' => 'apply', 'kind' => 'test.effect'];
    $skips = [$skip];
    if ($case === 'omitted') {
        $skips = [];
    } elseif ($case === 'duplicate') {
        $skips[] = $skip;
    } elseif ($case === 'foreign') {
        $foreign = app(StartOperationAction::class)->execute('test.effect', 'node', $this->peer->id, $this->peer->id, [], 'foreign', null);
        $foreign->update(['status' => OperationStatus::Failed]);
        $skips[0]['child_id'] = $foreign->id;

    } elseif ($case === 'empty reason') {
        $skips[0]['reason'] = ' ';
    } elseif ($case === 'long reason') {
        $skips[0]['reason'] = str_repeat('a', 501);
    }
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => $skips]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    expect($this->parent->refresh()->status)->toBe(OperationStatus::Running)->and($this->parent->lock_key)->not->toBeNull();
})->with(['omitted', 'duplicate', 'foreign', 'successful', 'empty reason', 'long reason']);

it('derives failed and cancelled parent skips without an echo and replays the completion', function (string $status): void {
    childKitSettled($this);
    $child = childKitAdmit($this);
    if ($status === 'cancelled') {
        app(CancelOperationAction::class)->execute($this->parent->refresh());
        expect($child->refresh()->status)->toBe(OperationStatus::Cancelled);
    } else {
        childKitFinish($this, $child, 'failed');
    }
    $body = ['worker' => 'parent', 'status' => $status, 'result' => ['skipped' => []]];
    if ($status === 'failed') {
        $body['error_code'] = 'test.failed';
    }
    $expected = [['child_id' => $child->id, 'reason' => $status, 'key' => 'apply', 'kind' => 'test.effect']];
    $this->postJson($this->path.'/complete', $body)->assertOk()->assertJsonPath('data.status', $status)->assertJsonPath('data.result.skipped', $expected);
    $this->postJson($this->path.'/complete', $body)->assertOk()->assertJsonPath('data.result.skipped', $expected);
    expect($this->parent->refresh()->lock_key)->toBeNull();
})->with(['failed', 'cancelled']);

it('stamps mixed-case child metadata only on completion and ignores caller key and kind', function (): void {
    childKitSettled($this);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'key' => 'Caddy-7']]]]);
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'Caddy-7'])->assertAccepted()->json('data.id');
    $child = Operation::findOrFail($id);
    childKitFinish($this, $child, 'failed');
    $result = ['skipped' => [['child_id' => $id, 'reason' => 'Node unavailable', 'key' => 'invented', 'kind' => 'invented.kind']]];
    expect(app(OperationExecutionContract::class)->result($this->parent, $result)['skipped'][0]['key'])->toBe('[REDACTED]');

    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => $result])->assertOk()
        ->assertJsonPath('data.result.skipped.0.key', 'Caddy-7')->assertJsonPath('data.result.skipped.0.kind', 'test.effect');
});

it('refuses child admission beyond its declared skipped receipt capacity', function (): void {
    childKitSettled($this);
    $this->parentSpec['result_fields'][0]['max'] = 1;
    ($this->register)();
    $child = childKitAdmit($this);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent, [...$this->intent, 'key' => 'second']]]]);
    $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'second'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.result_invalid');
    expect($this->parent->children()->count())->toBe(1);
    app(CancelOperationAction::class)->execute($this->parent->refresh());
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'cancelled', 'result' => ['skipped' => []]])->assertOk()->assertJsonPath('data.result.skipped.0.child_id', $child->id);
});

it('allows a listed optional child kind with no recorded intent', function (): void {
    childKitSettled($this);
    $this->parentSpec['children'][] = ['kind' => 'test.restore', 'node_from' => 'operation.node_id'];
    $this->parentSpec['completion_contract']['children_settled'][] = 'test.restore';
    ($this->register)();
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => []]])->assertOk();
    expect($this->parent->refresh()->lock_key)->toBeNull()->and($this->parent->children()->count())->toBe(1);
});

it('allows a wholly absent optional child plan', function (): void {
    childKitSettled($this);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => []]]);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => []]])->assertOk();
});

it('requires a terminal child for every recorded intent even when its kind already has a child', function (): void {
    childKitSettled($this);
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent, [...$this->intent, 'key' => 'second']]]]);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => []]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    expect($this->parent->refresh()->lock_key)->not->toBeNull();
});

it('refuses a recorded intent whose kind is outside the settled allowlist', function (): void {
    childKitSettled($this);
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent, [...$this->intent, 'key' => 'restore', 'kind' => 'test.restore']]]]);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => []]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
});

it('refuses unlisted admitted children even after they leave the current intent list', function (string $status): void {
    childKitSettled($this);
    $this->parentSpec['children'][] = ['kind' => 'test.restore', 'node_from' => 'operation.node_id'];
    ($this->register)();
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'key' => 'restore', 'kind' => 'test.restore']]]]);
    $id = $this->postJson($this->path.'/children', ['worker' => 'parent', 'key' => 'restore'])->assertAccepted()->json('data.id');
    childKitFinish($this, Operation::findOrFail($id), $status);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [$this->intent]]]);
    $skips = $status === 'succeeded' ? [] : [['child_id' => $id, 'reason' => 'unavailable']];
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => $skips]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
})->with(['succeeded', 'failed', 'cancelled']);

it('refuses a settled child whose admitted intent differs from the recorded plan', function (): void {
    childKitSettled($this);
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $this->parent->update(['state' => [...$this->parent->refresh()->state, 'child_intents' => [[...$this->intent, 'input' => [...$this->intent['input'], 'removed_node_id' => 999]]]]]);
    $this->postJson($this->path.'/complete', ['worker' => 'parent', 'status' => 'succeeded', 'result' => ['skipped' => []]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
});

function childKitCancelGuard($test): ChildKitCancelGuard
{
    $guard = new ChildKitCancelGuard;
    app()->instance(ChildKitCancelGuard::class, $guard);
    $test->parentSpec['cancel_guard'] = ChildKitCancelGuard::class;
    $test->childSpec['cancel_guard'] = ChildKitCancelGuard::class;
    ($test->register)();

    return $guard;
}

it('allows a guarded cancel only after checking the root and child before any write', function (): void {
    $guard = childKitCancelGuard($this);
    $child = childKitAdmit($this);
    $this->call('POST', '/api/v1/operations/'.$this->parent->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    expect(array_keys($guard->seen))->toBe([$this->parent->id, $child->id]);
    foreach ($guard->seen as $seen) {
        expect($seen['transaction'])->toBeGreaterThan(0)->and($seen['cancelled'])->toBe(0);
    }
    expect($this->parent->refresh()->cancel_requested_at)->not->toBeNull()
        ->and($child->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(OperationKinds::supports('cancel_guard'))->toBeTrue();
});

it('refuses guarded cancellation from recorded proof without changing the tree', function (string $member): void {
    $guard = childKitCancelGuard($this);
    $child = childKitAdmit($this);
    $blocked = $member === 'root' ? $this->parent : $child;
    if ($member === 'grandchild') {
        $blocked = app(StartOperationAction::class)->execute('test.effect', 'node', $this->peer->id, $this->peer->id, [], 'test', null);
        $blocked->forceFill(['parent_operation_id' => $child->id])->save();
    }
    $blocked->steps()->create(['position' => 1, 'name' => 'transfer-cutover', 'status' => 'succeeded', 'sequence' => 0, 'resumable' => false]);
    $before = Operation::query()->orderBy('id')->get()->map->getAttributes()->all();
    $this->call('POST', '/api/v1/operations/'.$this->parent->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertConflict()->assertJsonPath('error.code', 'operation.cancel_refused');
    expect(Operation::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and($guard->seen[$blocked->id]['transaction'])->toBeGreaterThan(0)
        ->and($guard->seen[$blocked->id]['cancelled'])->toBe(0);
})->with(['root', 'child', 'grandchild']);

it('does not consult guards when cancelling a terminal Operation', function (): void {
    $guard = childKitCancelGuard($this);
    $this->parent->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
    $this->parent->steps()->create(['position' => 1, 'name' => 'transfer-cutover', 'status' => 'succeeded', 'sequence' => 0, 'resumable' => false]);
    $before = $this->parent->refresh()->getAttributes();
    $this->call('POST', '/api/v1/operations/'.$this->parent->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    expect($guard->seen)->toBe([])->and($this->parent->refresh()->getAttributes())->toBe($before);
});

it('allows parent cancellation when a terminal child no longer needs its guard', function (): void {
    $guard = childKitCancelGuard($this);
    $child = childKitAdmit($this);
    childKitFinish($this, $child);
    $child->steps()->create(['position' => 2, 'name' => 'transfer-cutover', 'status' => 'succeeded', 'sequence' => 0, 'resumable' => false]);
    $this->call('POST', '/api/v1/operations/'.$this->parent->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    expect(array_keys($guard->seen))->toBe([$this->parent->id])->and($this->parent->refresh()->cancel_requested_at)->not->toBeNull()
        ->and($child->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('encodes an empty child receipt result as an object while retaining the children list', function (): void {
    $child = childKitAdmit($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.effect', 'worker' => 'child', 'operation_id' => $child->id])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$child->id.'/complete', ['worker' => 'child', 'status' => 'failed', 'error_code' => 'test.failed', 'result' => (object) []])->assertOk();
    $wire = json_decode($this->getJson($this->path.'/children')->assertOk()->getContent(), flags: JSON_THROW_ON_ERROR);
    expect($wire->data)->toBeArray()->and($wire->data[0]->result)->toBeInstanceOf(stdClass::class)
        ->and($child->refresh()->result)->toBe([]);
});
