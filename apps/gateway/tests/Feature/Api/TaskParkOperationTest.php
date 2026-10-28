<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskParkOperation;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Tasks\ParkTaskGroupRequest;
use Tests\Support\RecordingTaskVmProvider;

beforeEach(function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $node = $this->markAsGateway(Node::query()->create(['name' => 'park-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = Project::query()->create(['name' => 'Park', 'slug' => 'park', 'repository_url' => 'git@github.com:acme/park.git']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Batch', 'brief' => 'Batch', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Running, 'pr_url' => 'https://github.com/acme/park/pull/1', 'watched_pr_state' => 'open']);
    $this->child = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Update', 'brief' => 'Update', 'status' => TaskStatus::Completed]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'park-workspace', 'task_workspace_owner_id' => $this->group->id, 'branch_override' => 'task-'.$this->group->id, 'source_layout' => 'checkout', 'checkout_path' => '/srv/park', 'status' => InstanceState::SourceResolved]);
    $this->group->taskable()->associate($instance);
    $this->group->save();
    $this->vm = TaskVm::query()->create(['group_id' => $this->group->id, 'node_id' => $node->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-park', 'wireguard_ip' => '10.44.0.2', 'state' => TaskVmState::Ready]);
    $this->provider = new RecordingTaskVmProvider;
    $this->provider->observations = [new VmObservation(true, null), new VmObservation(false, null)];
    app()->instance(TaskVmProvider::class, $this->provider);
    $this->url = '/api/v1/task-groups/'.$this->group->id.'/park';
});

it('parks only after provider confirmation, preserves the workspace and replays the same key', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.park.1')->postJson($this->url)->assertAccepted()->json('data.id');
    expect($this->provider->calls)->toBe([]);
    run_operation_jobs();
    $operation = Operation::query()->findOrFail($id);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->kind)->toBe(OperationKinds::TasksPark)
        ->and($operation->result)->toBe(['group_id' => $this->group->id, 'task_vm_id' => $this->vm->id, 'instance_id' => $this->group->taskable_id, 'status' => 'waiting_for_review', 'power' => 'stopped'])
        ->and($this->group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Parked)
        ->and(Instance::query()->find($this->group->taskable_id))->not->toBeNull()
        ->and($this->provider->calls)->toBe(['observe', 'stop', 'observe']);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.id', $id);
    run_operation_jobs();
    expect($this->provider->calls)->toBe(['observe', 'stop', 'observe']);
});

it('observes an already parked guest without sending another stop', function (): void {
    $this->vm->update(['state' => TaskVmState::Parked]);
    $this->provider->observations = [new VmObservation(false, null)];
    $id = $this->withHeader('Idempotency-Key', 'review.already')->postJson($this->url)->assertAccepted()->json('data.id');
    run_operation_jobs();
    expect(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Succeeded)->and($this->provider->calls)->toBe(['observe']);
});

it('reconciles a lost stop reply using observed stopped power', function (): void {
    $this->provider->stopError = new RuntimeException('lost stop reply');
    $id = $this->withHeader('Idempotency-Key', 'review.lost')->postJson($this->url)->assertAccepted()->json('data.id');
    run_operation_jobs();
    expect(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Succeeded);
});

it('retains failure when a lost stop reply cannot be confirmed by a settled observation', function (): void {
    $provider = Mockery::mock(TaskVmProvider::class);
    $provider->expects('observe')->once()->ordered()->andReturn(new VmObservation(true, null));
    $provider->expects('stop')->once()->ordered()->andThrow(new RuntimeException('lost stop reply'));
    $provider->expects('observe')->once()->ordered()->andReturn(new VmObservation(false, null, settled: false));
    app()->instance(TaskVmProvider::class, $provider);
    $id = $this->withHeader('Idempotency-Key', 'review.transition')->postJson($this->url)->assertAccepted()->json('data.id');
    run_operation_jobs();
    expect(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Failed)
        ->and(Operation::query()->findOrFail($id)->error_code)->toBe('task_vm.not_settled');
});

it('never reports parking success for missing or still running guests', function (bool $missing): void {
    $this->provider->observations = $missing ? [null] : [new VmObservation(true, null), new VmObservation(true, null)];
    $id = $this->withHeader('Idempotency-Key', 'review.failed')->postJson($this->url)->assertAccepted()->json('data.id');
    run_operation_jobs();
    $operation = Operation::query()->findOrFail($id);
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe($missing ? 'task_vm.vm_missing' : 'task_vm.park_failed')
        ->and($operation->result['power'] ?? null)->not->toBe('stopped')
        ->and($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Ready)
        ->and($this->vm->fresh()->error_code)->toBe($operation->error_code);
})->with([true, false]);

it('refuses unsafe review parking without changing status or touching the provider', function (string $reason): void {
    match ($reason) {
        'child' => $this->child->update(['status' => TaskStatus::Running]),
        'preview' => $this->group->update(['preview' => true]),
        'assistance' => TaskAssistance::apply($this->group, AssistanceKind::Direction, 'Review', 'Review'),
        'child assistance' => TaskAssistance::apply($this->child, AssistanceKind::RemovalFailure, reason: 'Cleanup needs help'),
        'workspace owner' => Instance::query()->whereKey($this->group->taskable_id)->update(['task_workspace_owner_id' => null]),
        'no PR' => $this->group->update(['pr_url' => null]),
        'closed PR' => $this->group->update(['watched_pr_state' => 'closed']),
        'lease' => TaskWorkspaceLeaseRecord::query()->create(['group_id' => $this->group->id, 'instance_id' => $this->group->taskable_id, 'active_instance_id' => $this->group->taskable_id, 'kind' => 'check', 'owner' => 'other', 'status' => 'held', 'acquisition_sequence' => 1]),
    };
    $this->withHeader('Idempotency-Key', 'review.refuse')->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'tasks.park_refused');
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)->and($this->provider->calls)->toBe([]);
})->with(['child', 'preview', 'assistance', 'child assistance', 'workspace owner', 'no PR', 'closed PR', 'lease']);

it('rechecks eligibility when queued work starts', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.changed')->postJson($this->url)->assertAccepted()->json('data.id');
    $this->child->update(['status' => TaskStatus::Running]);
    run_operation_jobs();
    expect(Operation::query()->findOrFail($id)->error_code)->toBe('tasks.park_refused')->and($this->provider->calls)->toBe([]);
});

it('requires an empty body and key, authenticates the peer and keeps the group operation lock', function (): void {
    $this->postJson($this->url)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'review.body')->postJson($this->url, ['command' => 'stop'])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'review.busy')->postJson($this->url)->assertAccepted();
    $this->withHeader('Idempotency-Key', 'other.review')->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.15'])->postJson($this->url)->assertForbidden();
});

it('records the parking contract Fixtures', function (): void {
    $this->travelTo(Carbon::parse('2026-10-28T12:00:00Z'));
    $queued = $this->withHeader('Idempotency-Key', 'review.fixture')->postJson($this->url)->assertAccepted();
    record_fixture($queued, 'tasks-park-queued', ParkTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/park');
    $id = $queued->json('data.id');
    run_operation_jobs();
    record_fixture($this->getJson('/api/v1/operations/'.$id)->assertOk(), 'tasks-park-succeeded', ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
    $this->child->update(['status' => TaskStatus::Running]);
    record_fixture($this->withHeader('Idempotency-Key', 'review.refused')->postJson($this->url)->assertConflict(), 'tasks-park-refused', ParkTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/park');
});

it('refuses eligibility that changes during provider stop and preserves the prior group status', function (string $race): void {
    $provider = Mockery::mock(TaskVmProvider::class);
    $provider->expects('observe')->once()->ordered()->andReturn(new VmObservation(true, null));
    $provider->expects('stop')->once()->ordered()->andReturnUsing(function () use ($race): void {
        if ($race === 'child') {
            Task::query()->create(['parent_id' => $this->group->id, 'position' => 2, 'title' => 'New work', 'brief' => 'New work', 'status' => TaskStatus::Todo]);
        } else {
            TaskAssistance::apply($this->group, AssistanceKind::Direction, 'Review', 'Review');
        }
    });
    $provider->expects('observe')->once()->ordered()->andReturn(new VmObservation(false, null));
    app()->instance(TaskVmProvider::class, $provider);
    $id = $this->withHeader('Idempotency-Key', 'review.race')->postJson($this->url)->assertAccepted()->json('data.id');
    run_operation_jobs();
    $operation = Operation::query()->findOrFail($id);
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe('tasks.park_refused')
        ->and($operation->result)->toBeNull()->and($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Parked)->and($this->vm->fresh()->error_code)->toBe('tasks.park_refused');
})->with(['child', 'assistance']);

it('recovers a lost worker before the resumable park checkpoint by observing power without another stop', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.worker-lost')->postJson($this->url)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $crash = true;
    OperationStep::updating(function (OperationStep $step) use (&$crash): void {
        if ($crash && $step->name === 'park' && $step->status === OperationStepStatus::Succeeded) {
            throw new RuntimeException('Lost park worker before checkpoint.');
        }
    });
    $store = app(OperationStore::class);
    $store->claim($operation, 'gone-host:4242:1');
    expect(fn () => app(TaskParkOperation::class)->run($operation, new OperationRun($operation, $store)))
        ->toThrow(RuntimeException::class, 'Lost park worker before checkpoint.');
    $crash = false;
    $this->provider->observation = new VmObservation(false, null);
    expect($operation->fresh()->current_step)->toBe('park')
        ->and($operation->steps()->sole()->status)->toBe(OperationStepStatus::Running);
    expect(app(OperationSweeper::class)->sweep())->toBe(1);
    app(OperationRunner::class)->run($id);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->provider->calls)->toBe(['observe', 'stop', 'observe', 'observe']);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.id', $id);
});
