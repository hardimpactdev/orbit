<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Tasks\CheckTaskGroupRequest;
use Tests\Support\FakeTaskCheckRunner;

beforeEach(function (): void {
    enable_operation_queue();
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    $this->travelTo(now()->startOfSecond());
});

function check_operation_group(): Task
{
    $group = tick_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => TaskOrchestration::External->value]);
    $group->refresh();
    $group->tasks()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_group_id', $group->id)->delete();
    test()->markAsGateway($group->taskable->node);
    test()->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);

    return $group;
}

function check_operation_start(Task $group, string $phase = 'baseline', ?string $key = null): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => $phase], $key === null ? [] : ['Idempotency-Key' => $key])
        ->assertAccepted()->assertJsonPath('data.kind', 'tasks.check');

    return Operation::query()->findOrFail($response->json('data.id'));
}

it('runs a baseline once and records a passed result on the waiting read', function (): void {
    $group = check_operation_group();
    ProjectLifecycleStep::query()->create(['project_id' => $group->project_id, 'phase' => 'setup', 'name' => 'deps', 'command' => 'composer install', 'position' => 1, 'timeout_seconds' => 60]);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);

    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('passed');
    expect($operation->result['commit'])->toBeNull();
    expect(app(TaskCheckRunner::class)->setups)->toBe([[['name' => 'deps', 'command' => 'composer install', 'timeout_seconds' => 60]]]);
    expect(app(TaskCheckRunner::class)->commands)->toBe(['composer check']);
    expect(TaskCheck::query()->sole()->kind->value)->toBe('baseline');
    expect(app(TaskCheckRunner::class)->starts)->toBe(1);
});

it('returns 409 for a tick or ended group without starting a check', function (string $mode, string $status, string $code): void {
    $group = check_operation_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => $mode, 'status' => $status]);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'baseline'])
        ->assertConflict()->assertJsonPath('error.code', $code);

    expect(Operation::query()->count())->toBe(0);
    expect(app(TaskCheckRunner::class)->starts)->toBe(0);
})->with([
    ['tick', 'running', 'tasks.not_orchestrated'],
    ['external', 'completed', 'tasks.group_closed'],
    ['external', 'cancelled', 'tasks.group_closed'],
    ['external', 'failed', 'tasks.group_closed'],
]);

it('returns 409 without a workspace', function (): void {
    $group = check_operation_group();
    $group->taskable()->dissociate();
    $group->save();

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'baseline'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    expect(Operation::query()->count())->toBe(0);
});

it('returns 404 for a subtask outside the group', function (): void {
    $group = check_operation_group();
    $foreign = Task::query()->create(['parent_id' => Task::topLevel()->create(['title' => 'Other', 'brief' => 'Other.'])->id, 'title' => 'Other', 'brief' => 'Other.', 'position' => 1]);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $foreign->id, 'phase' => 'baseline'])->assertNotFound();
    expect(Operation::query()->count())->toBe(0);
});

it('returns 409 for a subtask after the lowest open position', function (): void {
    $group = check_operation_group();
    $later = Task::query()->create(['parent_id' => $group->id, 'title' => 'Later', 'brief' => 'Later.', 'position' => 2]);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $later->id, 'phase' => 'handoff'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.subtask_not_current');
    expect(Operation::query()->count())->toBe(0);
});

it('returns 409 for a baseline after an implementer started anywhere in the group', function (bool $linked): void {
    $group = check_operation_group();
    $task = $group->tasks()->firstOrFail();
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'role' => 'implementer', 'driver' => 'pi', 'external_id' => 'started-implementer', 'runtime_key' => 'pi:started-implementer', 'node_id' => $group->taskable->node_id]);
    if ($linked) {
        $task->update(['implementer_agent_thread_id' => $thread->id]);
    }

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $task->id, 'phase' => 'baseline'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.baseline_done');
    expect(Operation::query()->count())->toBe(0);
})->with([false, true]);

it('requires both typed inputs and rejects unknown fields', function (array $input): void {
    $group = check_operation_group();

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->count())->toBe(0);
})->with([[[]], [['subtask' => 1, 'phase' => 'review']], [['subtask' => '1', 'phase' => 'baseline']], [['subtask' => 1, 'phase' => 'baseline', 'command' => 'true']]]);

it('requires Gateway access and enabled Tasks', function (): void {
    $group = check_operation_group();
    $input = ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'baseline'];
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.254']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', $input)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);
    app(TaskExtensionState::class)->disable();

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', $input)->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    expect(Operation::query()->count())->toBe(0);
});

it('holds the group across operation kinds', function (): void {
    $group = check_operation_group();
    $holder = app(OperationStore::class)->create('tasks.provision', 'task_group', $group->id, null, [], 'test', null);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'baseline'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $holder->id);
    expect(Operation::query()->count())->toBe(1);
});

it('returns a failed or changed check as a successful operation with redacted evidence', function (bool $changed): void {
    $group = check_operation_group();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished($changed ? 0 : 1, str_repeat('a', 40), str_repeat($changed ? 'd' : 'b', 40), $changed ? ['generated.txt'] : [], "\033[31m2026-10-22T12:00:00Z failed token=ghp_abcdefghijklmnopqrstuvwxyz1234567890\033[0m\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe($changed ? 'changed' : 'failed');
    expect($operation->result['head'])->toBe(str_repeat('a', 40));
    expect($operation->result['tree'])->toBe(str_repeat($changed ? 'd' : 'b', 40));
    expect($operation->result['output'])->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz1234567890');
    expect($operation->result['output'])->not->toContain("\033");
    expect($runner->starts)->toBe(1);
})->with([false, true]);

it('verifies each deliverable and commits a passed handoff without a CI push', function (bool $passes): void {
    $group = check_operation_group();
    $task = $group->tasks()->firstOrFail();
    $task->update(['deliverables' => [
        ['id' => 'file', 'type' => 'file', 'description' => 'File exists.', 'path' => 'src/check.php', 'change' => 'created'],
        ['id' => 'command', 'type' => 'command', 'description' => 'Command passes.', 'command' => 'php check.php --token=ghp_abcdefghijklmnopqrstuvwxyz1234567890', 'directory' => '.'],
    ]]);
    $runner = new FakeTaskCheckRunner([FakeTaskCheckRunner::passed([
        'diff' => [['status' => 'A', 'path' => 'src/check.php']],
        'commands' => ['command' => ['exit_code' => $passes ? 0 : 1, 'output' => 'command result']],
    ])]);
    app()->instance(TaskCheckRunner::class, $runner);
    $signer = Mockery::mock(TaskWorkspaceSigner::class);
    if ($passes) {
        $signer->shouldReceive('commit')->once()->andReturnUsing(function () use ($runner): string {
            expect(app(TaskWorkspaceLease::class)->currentScope()->kind)->toBe('check_commit');
            $runner->head = str_repeat('c', 40);
            $runner->commitTree = str_repeat('b', 40);

            return $runner->head;
        });
    } else {
        $signer->shouldNotReceive('commit');
    }
    app()->instance(TaskWorkspaceSigner::class, $signer);
    \Pest\Laravel\mock(TaskPullRequestPublisher::class)->shouldNotReceive('pushHandoff');
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe($passes ? 'passed' : 'failed');
    expect($operation->result['commit'])->toBe($passes ? str_repeat('c', 40) : null);
    expect(TaskCheck::query()->sole()->commit_sha)->toBe($operation->result['commit']);
    expect($operation->result['deliverables'])->toBe([
        ['id' => 'file', 'outcome' => 'passed', 'failures' => []],
        ['id' => 'command', 'outcome' => $passes ? 'passed' : 'failed', 'failures' => $operation->result['deliverables'][1]['failures']],
    ]);
    expect($runner->deliverables[0]['commands'])->toBe([['id' => 'command', 'command' => 'php check.php --token=ghp_abcdefghijklmnopqrstuvwxyz1234567890', 'directory' => '.']]);
    expect(json_encode($operation->result, JSON_THROW_ON_ERROR))->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz1234567890');
    expect($runner->starts)->toBe(1);
})->with([false, true]);

it('waits for a busy workspace lease before starting the detached check with its own scope', function (): void {
    $group = check_operation_group();
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'operator', 'busy');
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect(app(TaskCheckRunner::class)->starts)->toBe(0);
    expect(TaskCheck::query()->count())->toBe(0);
    $leases->release($holder);
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect(app(TaskCheckRunner::class)->starts)->toBe(1);
    $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
    expect($lease->kind)->toBe('check');
    expect($lease->thread_id)->toBeNull();
    expect($lease->owner)->toBe('operation:'.$operation->id);
});

it('resumes a parked VM before it starts a check', function (): void {
    $group = check_operation_group();
    $vm = TaskVm::forGroup($group);
    $vm->update(['state' => TaskVmState::Parked]);
    Queue::fake([ResumeTaskVm::class]);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->current_step)->toBe('workspace_ready');
    expect(app(TaskCheckRunner::class)->starts)->toBe(0);
    Queue::assertPushed(ResumeTaskVm::class);
    $vm->update(['state' => TaskVmState::Ready]);
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect(app(TaskCheckRunner::class)->starts)->toBe(1);
    expect($operation->refresh()->current_step)->toBe('check');
});

it('stops a waiting check on direct cancel', function (): void {
    $group = check_operation_group();
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(app(TaskCheckRunner::class)->cancels)->toBe(1);
    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Cancelled);
    expect($operation->refresh()->lock_key)->toBeNull();
    expect($group->refresh()->status->value)->toBe('running');
});

it('records an unconfirmed cancel before 502 and retries its stop on the next waiting read', function (): void {
    $group = check_operation_group();
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    app(TaskCheckRunner::class)->failNextCancel = true;

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertStatus(502)->assertJsonPath('error.code', 'tasks.check_unreachable');

    expect($operation->refresh()->cancel_requested_at)->not->toBeNull();
    expect($operation->status)->toBe(OperationStatus::Waiting);
    expect($operation->lock_key)->toBe('task_group:'.$group->id);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect(app(TaskCheckRunner::class)->cancels)->toBe(2);
    expect(app(TaskCheckRunner::class)->starts)->toBe(1);
});

it('ends the operation and group without 502 when cancelling an unreachable VM', function (): void {
    $group = check_operation_group();
    $instance = $group->taskable;
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    app(TaskCheckRunner::class)->failNextCancel = true;
    bind_task_node_reachability(unreachable: true);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->lock_key)->toBeNull();
    expect($group->refresh()->taskable_id)->toBe($instance->id);
    expect($group->assistance_requested)->toBeTrue();
    expect($group->assistance_kind->value)->toBe('removal_failure');
    $this->assertModelExists($instance);
});

it('keeps a lost check waiting and held without retrying the effect', function (): void {
    $group = check_operation_group();
    $runner = new FakeTaskCheckRunner([TaskCheckReading::lost('No supervisor result.')]);
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Lost);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(1);
    expect($runner->starts)->toBe(1);
    expect($operation->attempts)->toBe(1);
});

it('replays a finished keyed check before later baseline validation and refuses changed input', function (): void {
    $group = check_operation_group();
    $operation = check_operation_start($group, key: 'check.baseline.c1');
    app(OperationRunner::class)->run($operation->id);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    $input = ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'baseline'];

    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', $input, ['Idempotency-Key' => 'check.baseline.c1'])
        ->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'succeeded');
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', [...$input, 'phase' => 'handoff'], ['Idempotency-Key' => 'check.baseline.c1'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    expect(Operation::query()->count())->toBe(1);
    expect(app(TaskCheckRunner::class)->starts)->toBe(1);
});

it('records the check refusal contract for a tick group', function (): void {
    $this->travelTo('2026-10-22 12:00:00');
    $group = check_operation_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'tick']);

    record_fixture($this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'baseline'])->assertConflict(),
        'tasks/tasks-check/not-orchestrated',
        CheckTaskGroupRequest::class,
        'POST /api/v1/task-groups/{group}/check');
});

it('keeps an external check waiting on a failed read without raising or changing assistance', function (): void {
    $group = check_operation_group();
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    TaskAssistance::apply($group, AssistanceKind::Failure, null, 'Orchestrator request.');
    app(TaskCheckRunner::class)->failRead = true;
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($group->refresh()->assistance_reason)->toBe('Orchestrator request.');
    expect($group->tasks()->firstOrFail()->communication_failures)->toBe(0);
    expect(app(TaskCheckRunner::class)->starts)->toBe(1);
});
