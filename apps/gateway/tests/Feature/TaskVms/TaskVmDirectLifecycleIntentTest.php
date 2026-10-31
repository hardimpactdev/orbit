<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->gateway = Node::query()->create(['name' => 'intent-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'intent-control', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit']);
    $this->worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id, 'swamp.run_token' => 'fixture.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->vm = TaskVm::query()->create(['name' => 'intent-vm', 'provider' => 'upcloud', 'state' => 'parked', 'wireguard_ip' => '10.44.0.129']);
    TaskVmSettingsBuilder::bind();
    $this->run = '3fc3ad54-423a-4b4b-8187-99514098426b';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vms', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    app()->instance(TaskVmProvider::class, Mockery::mock(TaskVmProvider::class));
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    $this->outcome = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T20:00:00.000Z',
        'finished_at' => '2026-10-30T20:00:01.000Z', 'result' => ['ready' => true]];
});

function admitLifecycleAcknowledgement(): Operation
{
    return app(StartOperationAction::class)->execute('task_vm.lifecycle_intent', 'task_vm', test()->vm->id, test()->gateway->id,
        ['task_vm_id' => test()->vm->id, 'effect_key' => 'lifecycle-intent'], 'operator', null);
}

it('defaults only this kind to direct and starts the existing family workflow after commit', function (): void {
    DB::beginTransaction();
    $operation = admitLifecycleAcknowledgement();
    expect(app(OperationKinds::class)->directOnly($operation->kind))->toBeTrue()
        ->and(DirectOperation::owns($operation))->toBeTrue()->and($this->socket->sent)->toBe([])
        ->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()->and($operation->attempts)->toBe(0);
    $path = '/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->run;
    $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    DB::commit();
    $this->getJson($path)->assertOk()->assertJsonPath('data.input', ['task_vm_id' => $this->vm->id]);
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-task-vms');
    expect(app(DirectOperation::class)->writer('task_vm.incus_stop'))->toBe('polled');
});

it('projects and replays acknowledgement without provider calls or lifecycle mutation', function (): void {
    $operation = admitLifecycleAcknowledgement();
    $before = $this->vm->fresh()->getAttributes();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $this->postJson($path, $this->outcome)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->postJson($path, $this->outcome)->assertOk();
    expect($operation->fresh()->result)->toBe(['ready' => true])
        ->and($operation->fresh()->state)->not->toHaveKey('executor_runs')
        ->and($this->vm->fresh()->getAttributes())->toBe($before);
});

it('records cancellation without acknowledging or changing the VM', function (): void {
    $operation = admitLifecycleAcknowledgement();
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [...$this->outcome, 'status' => 'cancelled', 'result' => []])->assertOk();
    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled)->and($this->vm->fresh()->state->value)->toBe('parked');
});

it('refuses false success, undeclared output, foreign runs and the deleted execution relay', function (): void {
    $operation = admitLifecycleAcknowledgement();
    $path = '/api/v1/direct-operations/'.$operation->id;
    foreach ([[], ['ready' => false], ['ready' => true, 'stdout' => 'private']] as $result) {
        $this->postJson($path.'/result', [...$this->outcome, 'result' => $result])->assertUnprocessable();
    }
    $this->postJson($path.'/result', [...$this->outcome, 'run_id' => 'f5518d34-4395-4454-ab37-7f3cdcae1ad4'])->assertConflict();
    $this->postJson($path.'/primitive', ['run_id' => $this->run])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', ['worker' => 'forged', 'key' => 'lifecycle-intent',
        'transport' => 'task_vm_lifecycle', 'name' => 'task_vm.lifecycle', 'input' => ['task_vm_id' => $this->vm->id, 'action' => 'intent']])->assertStatus(422);
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('refuses explicit polled fallback before recording work', function (): void {
    config(['swamp.direct_writers' => ['task_vm.lifecycle_intent' => 'polled']]);
    expect(fn () => admitLifecycleAcknowledgement())->toThrow(ResourceOperationException::class, 'This kind requires its direct Swamp writer.');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('preserves canonical child proof for existing lifecycle parents', function (): void {
    $parent = app(StartTaskVmLifecycleOperationAction::class)->execute($this->vm->id, 'resume');
    $child = app(OperationChildren::class)->admit($parent, ['key' => 'lifecycle-intent'])['operation'];
    $this->postJson('/api/v1/direct-operations/'.$child->id.'/result', $this->outcome)->assertOk();
    expect(app(OperationChildren::class)->requireSucceeded($parent, 'lifecycle-intent')->id)->toBe($child->id)
        ->and($child->parent_operation_id)->toBe($parent->id)->and($child->node_id)->toBe($this->gateway->id)
        ->and($this->vm->fresh()->state->value)->toBe('parked');
});
