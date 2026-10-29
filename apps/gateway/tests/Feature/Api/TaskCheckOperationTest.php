<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Infrastructure\Tasks\RemoteWorkspaceOperationJournal;
use App\Jobs\TaskVms\PublishTaskVmCommit;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Tasks\CheckTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\RecordingTaskVmProvider;
use Tests\Support\StreamingLocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

beforeEach(function (): void {
    enable_operation_queue();
    test_bind_snapshot_driver();
    app()->instance(TaskVmProvider::class, new RecordingTaskVmProvider);
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app()->instance(TaskWorkspaceStateReader::class, new readonly class implements TaskWorkspaceStateReader
    {
        public function headCommit(Instance $instance): ?string
        {
            return null;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return null;
        }
    });
    app(TaskExtensionState::class)->enable();
    $this->travelTo(now()->startOfSecond());
});

function check_operation_fixture_group(): Task
{
    $project = Project::query()->create([
        'name' => 'tick-app',
        'slug' => 'tick-app',
        'repository_url' => 'git@example.test:tick.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'tick-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.212',
        'wireguard_ip' => '10.44.0.212',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Tick routing',
        'brief' => 'Observe, classify, and execute.',
        'status' => TaskGroupStatus::Running,
    ]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $group->id,
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/tick-app/task-21',
        'starting_commit' => str_repeat('a', 40),
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
        'started_at' => now(),
    ]);

    test_link_agent_threads($group);

    return test_vm_group($group);
}

function check_operation_group(): Task
{
    $group = check_operation_fixture_group();
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

it('retains a lost start reply and recovers the real check result on a delayed read', function (int $exitCode): void {
    $group = check_operation_group();
    $runner = Mockery::mock(TaskCheckRunner::class);
    $runner->shouldReceive('snapshot')->andReturn(new TaskWorkspaceSnapshot(str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('start')->once()->andThrow(new TaskCheckException('SSH connection lost after dispatch.'));
    $runner->shouldReceive('recoverStart')->once()->withArgs(fn ($instance, string $runId): bool => $instance->id === $group->taskable_id && $runId === TaskWorkspaceLeaseRecord::query()->sole()->check_run_id)
        ->andReturn(new TaskCheckProcess(4001, 'identity', str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('read')->once()->withArgs(fn ($instance, TaskCheckProcess $process): bool => $process->pid === 4001 && $process->runId === TaskWorkspaceLeaseRecord::query()->sole()->check_run_id)
        ->andReturn(TaskCheckReading::finished($exitCode, str_repeat('a', 40), str_repeat('b', 40), [], 'Recovered check result.'));
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group, key: 'check.start-reply-lost');

    app(OperationRunner::class)->run($operation->id);

    $check = TaskCheck::query()->sole();
    $lease = TaskWorkspaceLeaseRecord::query()->sole();
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->lock_key)->toBe('task_group:'.$group->id);
    expect($operation->state['check_id'])->toBe($check->id);
    expect($check->status)->toBe(TaskCheckStatus::Running);
    expect($lease->status)->toBe('held');
    expect($lease->check_id)->toBe($check->id);
    expect($lease->owner)->toBe('operation:'.$operation->id);
    expect($lease->pid)->toBe(0);
    $this->travel(10)->seconds();

    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['check_id'])->toBe($check->id);
    expect($operation->result['outcome'])->toBe($exitCode === 0 ? 'passed' : 'failed');
    expect($operation->result['output'])->toBe('Recovered check result.');
    expect($operation->lock_key)->toBeNull();
    expect($check->refresh()->status)->toBe($exitCode === 0 ? TaskCheckStatus::Passed : TaskCheckStatus::Failed);
    expect($lease->refresh()->status)->toBe('released');
})->with([0, 1]);

it('stops a check whose start reply was lost and retains ownership until its stop is confirmed', function (bool $readFirst, string $unreachable): void {
    $group = check_operation_group();
    $runner = Mockery::mock(TaskCheckRunner::class);
    $runner->shouldReceive('snapshot')->andReturn(new TaskWorkspaceSnapshot(str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('start')->once()->andThrow(new TaskCheckException('SSH connection lost after dispatch.'));
    if ($unreachable === 'recovery') {
        $runner->shouldReceive('recoverStart')->once()->ordered()->andThrow(new TaskCheckException('The Node is unreachable.'));
    }
    $runner->shouldReceive('recoverStart')->once()->ordered()->andReturn(new TaskCheckProcess(4001, 'identity', str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('read')->andReturn(TaskCheckReading::running(), FakeTaskCheckRunner::passed());
    if ($unreachable === 'stop') {
        $runner->shouldReceive('cancel')->once()->ordered()->andThrow(new TaskCheckException('The Node is unreachable.'));
    }
    $runner->shouldReceive('cancel')->once()->ordered()->withArgs(fn ($instance, TaskCheckProcess $process): bool => $process->pid === 4001)->andReturnNull();
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $check = TaskCheck::query()->sole();
    $lease = TaskWorkspaceLeaseRecord::query()->sole();
    if ($readFirst) {
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
    }

    $response = $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

    if ($unreachable !== '') {
        $response->assertStatus(502)->assertJsonPath('error.code', 'tasks.check_unreachable');
        expect($operation->refresh()->cancel_requested_at)->not->toBeNull();
        expect($operation->status)->toBe(OperationStatus::Waiting);
        expect($operation->lock_key)->toBe('task_group:'.$group->id);
        expect($check->refresh()->status)->toBe(TaskCheckStatus::Running);
        expect($lease->refresh()->status)->toBe('held');
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
    } else {
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->lock_key)->toBeNull();
    expect($check->refresh()->status)->toBe(TaskCheckStatus::Cancelled);
    expect($lease->refresh()->status)->toBe('released');
})->with([
    'read then cancel' => [true, ''],
    'cancel before read' => [false, ''],
    'unconfirmed stop' => [false, 'stop'],
    'unconfirmed recovery' => [false, 'recovery'],
]);

it('fails cleanly when the check start never reached the VM', function (bool $recover): void {
    $group = check_operation_group();
    $exception = new TaskCheckException('The check start was not dispatched.');
    $exception->startNotDispatched = ! $recover;
    $runner = Mockery::mock(TaskCheckRunner::class);
    $runner->shouldReceive('snapshot')->andReturn(new TaskWorkspaceSnapshot(str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('start')->once()->andThrow($exception);
    if ($recover) {
        $runner->shouldReceive('recoverStart')->once()->andReturnNull();
    } else {
        $runner->shouldNotReceive('recoverStart');
    }
    $runner->shouldNotReceive('cancel');
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);

    app(OperationRunner::class)->run($operation->id);
    if ($recover) {
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
    }

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe('tasks.check_unreachable');
    expect($operation->lock_key)->toBeNull();
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
    if ($recover) {
        expect(TaskCheck::query()->count())->toBe(0);
    } else {
        $check = TaskCheck::query()->findOrFail($operation->state['check_id']);
        expect($check->status)->toBe(TaskCheckStatus::Cancelled);
        expect($check->pid)->toBe(0);
        expect($check->finished_at)->not->toBeNull();
    }
    $task = $group->tasks()->firstOrFail();
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id.'/check/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
        ->assertConflict()->assertJsonPath('error.code', 'tasks.check_not_running');

    $nextRunner = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $nextRunner);
    $next = check_operation_start($group);
    app(OperationRunner::class)->run($next->id);

    expect($next->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($next->state['check_id'])->not->toBe($operation->state['check_id']);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->check_id)->toBe($next->state['check_id']);
    expect($nextRunner->starts)->toBe(1);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($next->id);

    expect($next->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($next->result['outcome'])->toBe('passed');
    expect(TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])->count())->toBe(0);
})->with(['before dispatch' => false, 'recovery fences an absent start' => true]);

it('recovers a lost start reply before public check cancellation and keeps ownership on 502', function (string $unreachable): void {
    $group = check_operation_group();
    $task = $group->tasks()->firstOrFail();
    $runner = Mockery::mock(TaskCheckRunner::class);
    $runner->shouldReceive('snapshot')->andReturn(new TaskWorkspaceSnapshot(str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('start')->once()->andThrow(new TaskCheckException('SSH connection lost after dispatch.'));
    if ($unreachable === 'recovery') {
        $runner->shouldReceive('recoverStart')->once()->ordered()->andThrow(new TaskCheckException('The Node is unreachable.'));
    }
    $runner->shouldReceive('recoverStart')->once()->ordered()->andReturn(new TaskCheckProcess(4001, 'identity', str_repeat('a', 40), str_repeat('b', 40)));
    $runner->shouldReceive('read')->andReturn(TaskCheckReading::running(), FakeTaskCheckRunner::passed());
    if ($unreachable === 'stop') {
        $runner->shouldReceive('cancel')->once()->ordered()->andThrow(new TaskCheckException('The Node is unreachable.'));
    }
    $runner->shouldReceive('cancel')->once()->ordered()->withArgs(fn ($instance, TaskCheckProcess $process): bool => $process->pid === 4001)->andReturnNull();
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $check = TaskCheck::query()->sole();
    $lease = TaskWorkspaceLeaseRecord::query()->sole();
    $url = '/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id.'/check/cancel';

    $response = $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

    if ($unreachable !== '') {
        $response->assertStatus(502)->assertJsonPath('error.code', 'tasks.check_unreachable');
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->lock_key)->toBe('task_group:'.$group->id);
        expect($check->refresh()->status)->toBe(TaskCheckStatus::Running);
        expect($lease->refresh()->status)->toBe('held');
        $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
    } else {
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('failed');
    expect($operation->lock_key)->toBeNull();
    expect($check->refresh()->status)->toBe(TaskCheckStatus::Cancelled);
    expect($lease->refresh()->status)->toBe('released');
})->with(['', 'recovery', 'stop']);

it('reconciles its recorded check after a terminal subtask update until the check finishes', function (string $status, int $exitCode): void {
    $group = check_operation_group();
    $task = $group->tasks()->firstOrFail();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::running(),
        TaskCheckReading::finished($exitCode, str_repeat('a', 40), str_repeat('b', 40), [], 'Recorded check result.'),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $check = TaskCheck::query()->sole();
    $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();

    $this->patchJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id, ['status' => $status])->assertOk();
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->lock_key)->toBe('task_group:'.$group->id);
    expect($operation->state['check_id'])->toBe($check->id);
    expect($check->refresh()->status)->toBe(TaskCheckStatus::Running);
    expect($lease->refresh()->status)->toBe('held');
    expect($lease->check_id)->toBe($check->id);

    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['check_id'])->toBe($check->id);
    expect($operation->result['outcome'])->toBe($exitCode === 0 ? 'passed' : 'failed');
    expect($operation->result['output'])->toBe('Recorded check result.');
    expect($operation->lock_key)->toBeNull();
    expect($check->refresh()->status)->toBe($exitCode === 0 ? TaskCheckStatus::Passed : TaskCheckStatus::Failed);
    expect($lease->refresh()->status)->toBe('released');
    expect($task->refresh()->status->value)->toBe($status);
    expect($runner->starts)->toBe(1);
})->with(['completed', 'failed'])->with([0, 1]);

it('stops its recorded check after a terminal subtask update and retains ownership until stop is confirmed', function (string $status, bool $unreachable): void {
    $group = check_operation_group();
    $task = $group->tasks()->firstOrFail();
    $runner = new FakeTaskCheckRunner([TaskCheckReading::running(), FakeTaskCheckRunner::passed()]);
    app()->instance(TaskCheckRunner::class, $runner);
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $check = TaskCheck::query()->sole();
    $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();

    $this->patchJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id, ['status' => $status])->assertOk();
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    $runner->failNextCancel = $unreachable;

    $response = $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');

    if ($unreachable) {
        $response->assertStatus(502)->assertJsonPath('error.code', 'tasks.check_unreachable');
        expect($operation->refresh()->cancel_requested_at)->not->toBeNull();
        expect($operation->status)->toBe(OperationStatus::Waiting);
        expect($operation->lock_key)->toBe('task_group:'.$group->id);
        expect($check->refresh()->status)->toBe(TaskCheckStatus::Running);
        expect($lease->refresh()->status)->toBe('held');
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
    } else {
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->lock_key)->toBeNull();
    expect($check->refresh()->status)->toBe(TaskCheckStatus::Cancelled);
    expect($lease->refresh()->status)->toBe('released');
    expect($runner->cancels)->toBe($unreachable ? 2 : 1);
    expect($runner->starts)->toBe(1);
})->with(['completed', 'failed'])->with([false, true]);

it('records an unconfirmed cancel before 502 and retries its stop on the next waiting read', function (): void {
    $group = check_operation_group();
    $operation = check_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    app(TaskCheckRunner::class)->failNextCancel = true;
    $outerTransactionLevel = DB::transactionLevel();

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertStatus(502)->assertJsonPath('error.code', 'tasks.check_unreachable');

    expect(app(TaskCheckRunner::class)->cancelTransactionLevels)->toBe([$outerTransactionLevel]);
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

/** Isolated external fixture: production review-and-merge admission remains closed. */
function hosted_operation_fixture(bool $policy = true): array
{
    $group = check_operation_group();
    $group->project->update(['review_and_merge' => $policy, 'merge_check' => 'Required checks', 'repository_url' => 'https://github.com/acme/shop.git']);
    $group->tasks()->firstOrFail()->update(['title' => "Models [skip ci] [CI SKIP] [no ci] [skip actions] [actions skip]\n\nskip-checks: true"]);
    $runner = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $runner);
    $effects = (object) ['commits' => [], 'messages' => [], 'calls' => [], 'snapshots' => [], 'reads' => 0];
    $signer = Mockery::mock(TaskWorkspaceSigner::class);
    $signer->shouldReceive('commit')->andReturnUsing(function ($instance, string $message, bool $allowEmpty = false, mixed $receipt = null) use ($effects, $runner): ?string {
        $effects->calls[] = [$message, $allowEmpty, $receipt];
        if ($receipt === null) {
            // An ordinary unchanged-tree commit reuses HEAD. Hosted handoff CI must not take this path.
            $runner->commitTree = $runner->tree;

            return $runner->head;
        }
        $key = $receipt->operation;
        if (! isset($effects->commits[$key])) {
            if ($runner->head !== $receipt->head || $runner->tree !== $receipt->tree) {
                return null;
            }
            $effects->commits[$key] = hash('sha1', 'operation-'.$key);
            $effects->messages[] = $message;
            $runner->head = $effects->commits[$key];
            $runner->commitTree = $runner->tree;
            if (is_callable($effects->onCommit ?? null)) {
                ($effects->onCommit)();
            }
        }

        return $effects->commits[$key];
    });
    app()->instance(TaskWorkspaceSigner::class, $signer);
    $merger = Mockery::mock(TaskPullRequestMerger::class);
    $merger->shouldReceive('commitChecks')->andReturnUsing(function (Task $group, string $commit) use ($effects): ?array {
        $effects->reads++;
        $snapshot = array_shift($effects->snapshots);
        if ($snapshot instanceof Throwable) {
            throw $snapshot;
        }
        if (is_callable($snapshot)) {
            return $snapshot($commit);
        }

        return $snapshot ?? ['runs' => [], 'suites' => []];
    });
    app()->instance(TaskPullRequestMerger::class, $merger);
    Http::preventStrayRequests();
    Queue::fake([PublishTaskVmCommit::class]);

    return [$group, $runner, $effects];
}

function hosted_operation_read(Operation $operation): void
{
    test()->travel(30)->seconds();
    app(OperationRunner::class)->read($operation->id);
}

function hosted_operation_pushed(Task $group, Operation $operation): void
{
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->current_step)->toBe('handoff_push');
    TaskVm::forGroup($group)->update(['publish_state' => TaskVmPublishState::Pushed]);
    hosted_operation_read($operation);
}

function hosted_operation_run(string $commit, ?string $conclusion = 'success', string $name = 'Required checks', string $app = 'github-actions', string $status = 'completed'): GitHubCheckRun
{
    return new GitHubCheckRun($name, $conclusion, 'https://github.com/acme/shop/actions/runs/1', 1, now()->toIso8601String(), $status, $commit, $app,
        outputSummary: "\033[31mtoken=ghp_abcdefghijklmnopqrstuvwxyz1234567890\033[0m", checkSuiteId: 1);
}

it('hosted handoff CI operation commits one fresh sanitized checked tree and waits for the exact named pass', function (): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff', 'hosted.positive');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $check = TaskCheck::query()->sole();
    expect($check->commit_sha)->not->toBe($check->head_after);
    expect($check->tree_after)->toBe($runner->commitTree);
    expect($effects->commits)->toHaveCount(1);
    expect($effects->calls[0][1])->toBeTrue();
    expect($effects->calls[0][2])->not->toBeNull();
    expect($effects->calls[0][0])->not->toMatch('/\[(?:skip ci|ci skip|no ci|skip actions|actions skip)\]|skip-checks\s*:\s*true/i');
    expect($operation->refresh()->state['ci']['check_name'])->toBe('Required checks');
    expect($operation->state['ci']['branch'])->toBe('task-'.$group->id.'-handoff');
    Queue::assertPushed(PublishTaskVmCommit::class, fn ($job): bool => $job->branch === 'task-'.$group->id.'-handoff' && $job->commit === $check->commit_sha);
    Queue::assertPushed(PublishTaskVmCommit::class, 1);
    $effects->snapshots = [fn (string $sha): array => ['runs' => [hosted_operation_run($sha, null, status: 'in_progress')], 'suites' => [new GitHubCheckSuite(1, 'in_progress', null, 'github-actions')]]];
    hosted_operation_pushed($group, $operation);
    expect($operation->refresh()->current_step)->toBe('hosted_ci');
    expect($operation->status)->toBe(OperationStatus::Waiting);
    expect($operation->worker)->toBeNull();
    expect($operation->result)->toBeNull();
    expect($operation->state['ci']['state'])->toBe('pending');
    expect($operation->state['ci']['pushed_at'])->not->toBeNull();
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $check->task_id, 'phase' => 'handoff'])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    // Another target completes while the CI wait retains only this target hold.
    $node = $group->taskable->node->replicate();
    $node->fill(['name' => 'other-node', 'public_ssh_host' => '10.44.0.213', 'wireguard_ip' => '10.44.0.213'])->save();
    $workspace = test_ready_task_workspace($group->project, $node, ['name' => 'other', 'checkout_path' => '/tmp/other-check'], ['orchestration' => 'external']);
    $other = Task::topLevel()->findOrFail($workspace->task_workspace_owner_id);
    $other->tasks()->create(['title' => 'Other', 'brief' => 'Other.', 'position' => 1]);
    $otherOperation = check_operation_start($other);
    app(OperationRunner::class)->run($otherOperation->id);
    hosted_operation_read($otherOperation);
    expect($otherOperation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($otherOperation->result['ci'])->toBeNull();
    // Recovery must keep the original plan despite a later Project edit.
    $group->project->update(['merge_check' => 'Renamed check']);
    $effects->snapshots = [fn (string $sha): array => ['runs' => [hosted_operation_run($sha)], 'suites' => [new GitHubCheckSuite(1, 'completed', 'success', 'github-actions')]]];
    hosted_operation_read($operation);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('passed');
    expect($operation->result['check_id'])->toBe($check->id);
    expect($operation->result['commit'])->toBe($check->commit_sha);
    expect($operation->result['head'])->toBe($check->head_after);
    expect($operation->result['tree'])->toBe($check->tree_after);
    expect($operation->result['ci'])->toBe($operation->state['ci']);
    expect($operation->result['ci']['check_name'])->toBe('Required checks');
    expect($operation->result['ci']['state'])->toBe('passed');
    expect($operation->result['ci']['fault'])->toBeNull();
    expect($operation->result['ci']['runs'][0])->toHaveKeys(array_keys(hosted_operation_run($check->commit_sha)->toArray()));
    expect($operation->result['ci']['suites'][0])->toBe((new GitHubCheckSuite(1, 'completed', 'success', 'github-actions'))->toArray());
    $wire = $this->getJson('/api/v1/operations/'.$operation->id)->assertOk()->assertJsonPath('data.result.ci.state', 'passed')->json('data');
    require_once base_path('../../packages/php-sdk/src/Responses/Operations/OperationStepResponse.php');
    require_once base_path('../../packages/php-sdk/src/Responses/Operations/OperationResponse.php');
    $sdk = OperationResponse::fromGatewayData($wire, 'hosted-ci-contract');
    expect($sdk->result)->toBe($operation->result);
    expect(array_keys($sdk->result['ci']))->toBe(['branch', 'commit', 'check_name', 'state', 'fault', 'pushed_at', 'observed_at', 'runs', 'suites']);
    expect(json_encode($operation->result, JSON_THROW_ON_ERROR))->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz1234567890')->not->toContain('\\u001b');
    expect($runner->starts)->toBe(2); // one handoff, one other target's baseline
    expect($effects->commits)->toHaveCount(1);
    Queue::assertPushed(PublishTaskVmCommit::class, 1);
    Http::assertNothingSent();
    expect(AgentThread::query()->count())->toBe(0);
    expect($group->refresh()->pr_url)->toBeNull();
    expect($group->assistance_requested)->toBeFalse();
    expect($group->tasks()->firstOrFail()->assistance_requested)->toBeFalse();
    expect($group->tasks()->firstOrFail()->status->value)->toBe('running');
    expect(check_operation_start($group, 'handoff', 'hosted.positive')->id)->toBe($operation->id);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $check->task_id, 'phase' => 'baseline'], ['Idempotency-Key' => 'hosted.positive'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
});

it('hosted handoff CI operation preserves baseline and no-policy paths without a push or read', function (string $phase, bool $policy): void {
    [$group, $runner, $effects] = hosted_operation_fixture($policy);
    if ($phase === 'handoff') {
        $signer = Mockery::mock(TaskWorkspaceSigner::class);
        $signer->shouldReceive('commit')->once()->withArgs(fn ($instance, $message, $allowEmpty = false, $receipt = null): bool => ! $allowEmpty && $receipt === null)->andReturnUsing(function () use ($runner): string {
            $runner->commitTree = $runner->tree;

            return $runner->head;
        });
        app()->instance(TaskWorkspaceSigner::class, $signer);
    }
    $operation = check_operation_start($group, $phase);
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('passed');
    expect($operation->result['ci'])->toBeNull();
    expect($effects->reads)->toBe(0);
    expect($runner->starts)->toBe(1);
    Queue::assertNotPushed(PublishTaskVmCommit::class);
})->with([['baseline', true], ['handoff', false]]);

it('hosted handoff CI operation waits with bounded classified evidence instead of charging failures or sending turns', function (string $case, string $state, ?string $fault): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $effects->snapshots = [function (string $sha) use ($case): array {
        $run = match ($case) {
            'pending' => hosted_operation_run($sha, null, status: 'queued'),
            'skipped', 'neutral', 'cancelled' => hosted_operation_run($sha, $case),
            'foreign' => hosted_operation_run($sha, app: 'foreign'),
            'wrong-head' => hosted_operation_run(str_repeat('f', 40)),
            default => null,
        };
        if (in_array($case, ['unreadable', 'overflow', 'incomplete'], true)) {
            throw new TaskPullRequestException('Incomplete/overflowing GitHub snapshot token=ghp_abcdefghijklmnopqrstuvwxyz1234567890');
        }

        return ['runs' => $run === null ? [] : [$run], 'suites' => $case === 'missing-job' ? [new GitHubCheckSuite(1, 'completed', 'success', 'github-actions')] : []];
    }];
    if ($case === 'no-suite-timeout') {
        $this->travel(601)->seconds();
    }
    hosted_operation_pushed($group, $operation);
    // Age is measured from the confirmed push, not from local check start.
    if ($case === 'no-suite-timeout') {
        $this->travel(601)->seconds();
        hosted_operation_read($operation);
    }
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->state['ci']['state'])->toBe($state);
    expect($operation->state['ci']['fault'])->toBe($fault);
    expect($operation->state['ci']['observed_at'])->not->toBeNull();
    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Passed);
    expect(TaskCheck::query()->sole()->failed_step)->toBeNull();
    expect($group->tasks()->firstOrFail()->assistance_requested)->toBeFalse();
    expect(AgentThread::query()->count())->toBe(0);
    expect($runner->starts)->toBe(1);
    expect(json_encode($operation->state, JSON_THROW_ON_ERROR))->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz1234567890');
})->with([
    ['missing', 'missing', null], ['pending', 'pending', null], ['unreadable', 'unreadable', 'infrastructure'],
    ['overflow', 'unreadable', 'infrastructure'], ['incomplete', 'unreadable', 'infrastructure'],
    ['skipped', 'failed', 'configuration'], ['neutral', 'failed', 'configuration'], ['foreign', 'failed', 'configuration'],
    ['wrong-head', 'failed', 'configuration'], ['cancelled', 'failed', 'infrastructure'],
    ['missing-job', 'missing', 'configuration'], ['no-suite-timeout', 'missing', 'configuration'],
]);

it('hosted handoff CI operation returns code or workflow failure as a succeeded failed outcome with redacted evidence', function (bool $workflow): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $output = Mockery::mock(TaskPullRequestCheckOutput::class);
    $output->shouldReceive('read')->andReturn("failure token=ghp_abcdefghijklmnopqrstuvwxyz1234567890\033[31m");
    app()->instance(TaskPullRequestCheckOutput::class, $output);
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $effects->snapshots = [fn (string $sha): array => ['runs' => $workflow ? [] : [hosted_operation_run($sha, 'failure')], 'suites' => [new GitHubCheckSuite(1, 'completed', 'failure', 'github-actions')]]];
    hosted_operation_pushed($group, $operation);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('failed');
    expect($operation->result['ci']['state'])->toBe('failed');
    expect($operation->result['ci']['fault'])->toBe('change');
    expect(TaskCheck::query()->sole()->failed_step)->toBe('ci');
    expect(json_encode($operation->result, JSON_THROW_ON_ERROR))->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz1234567890');
    expect($runner->starts)->toBe(1);
})->with([false, true]);

it('hosted handoff CI operation creates H2 after cancelled unchanged H and rejects cancelled local passes at both consumers', function (): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff', 'hosted.H');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $effects->snapshots = [fn (string $sha): array => ['runs' => [hosted_operation_run($sha, 'cancelled')], 'suites' => []]];
    hosted_operation_pushed($group, $operation);
    $check = TaskCheck::query()->sole();
    $first = $check->commit_sha;
    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($operation->refresh()->state['ci']['commit'])->toBe($first);
    expect($operation->state['ci']['fault'])->toBe('infrastructure');
    expect($operation->result['ci'])->toBe($operation->state['ci']);
    expect(TaskVm::forGroup($group)->publish_state)->toBe(TaskVmPublishState::Pushed);
    expect($check->refresh()->status)->toBe(TaskCheckStatus::Passed);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', ['subtask' => $check->task_id, 'thread' => 'new', 'role' => 'reviewer', 'prompt' => 'Review.', 'check' => $check->id])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
    $next = check_operation_start($group, 'handoff', 'hosted.H2');
    app(OperationRunner::class)->run($next->id);
    hosted_operation_read($next);
    $second = TaskCheck::query()->latest('id')->firstOrFail();
    expect($second->commit_sha)->not->toBe($first);
    expect($second->tree_after)->toBe($check->tree_after);
    expect($second->head_after)->toBe($first);
    expect($effects->messages)->toHaveCount(2);
    expect(check_operation_start($group, 'handoff', 'hosted.H2')->id)->toBe($next->id);
    $effects->snapshots = [fn (string $sha): array => ['runs' => [hosted_operation_run($sha)], 'suites' => []]];
    hosted_operation_pushed($group, $next);
    app(OperationRunner::class)->run($next->id); // lost completed response is harmless
    expect($next->refresh()->result['outcome'])->toBe('passed');
    expect($next->result['commit'])->toBe($second->commit_sha);
    expect($effects->commits)->toHaveCount(2);
    expect($runner->starts)->toBe(2);
    Queue::assertPushed(PublishTaskVmCommit::class, 2);
    Http::assertNothingSent();
});

it('hosted handoff CI operation cancellation withdraws queued pushes but fences a started push until terminal reconciliation', function (bool $started): void {
    [$group, $runner] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $vm = TaskVm::forGroup($group);
    if ($started) {
        $vm->update(['publish_started_at' => now()]);
    }
    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    if ($started) {
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->lock_key)->not->toBeNull();
        $vm->update(['publish_state' => TaskVmPublishState::Pushed]);
        hosted_operation_read($operation);
    }
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->lock_key)->toBeNull();
    expect($vm->refresh()->publish_state)->toBe($started ? TaskVmPublishState::Pushed : null);
    expect($runner->cancels)->toBe(0);
    expect($runner->starts)->toBe(1);
    Http::assertNothingSent();
})->with([false, true]);

it('hosted handoff CI operation never reports a pass after workspace movement', function (string $boundary): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    if ($boundary === 'commit') {
        $runner->tree = str_repeat('d', 40);
    }
    hosted_operation_read($operation);
    if ($boundary !== 'commit') {
        if ($boundary === 'push') {
            $runner->head = str_repeat('d', 40);
        } else {
            $effects->snapshots = [function (string $sha) use ($runner): array {
                $runner->tree = str_repeat('d', 40);

                return ['runs' => [hosted_operation_run($sha)], 'suites' => []];
            }];
        }
        hosted_operation_pushed($group, $operation);
    }
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('changed');
    expect($runner->starts)->toBe(1);
})->with(['commit', 'push', 'read']);

it('hosted handoff CI operation resumes real commit and push effects after lost replies without H2 or another local check', function (string $reply): void {
    [$group, $fakeRunner, $effects] = hosted_operation_fixture();
    $root = TestOrbitHome::scratch('hosted-ci-recovery');
    mkdir($root, 0700, true);
    $git = static fn (string $directory, string ...$args): string => trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]))->mustRun()->getOutput());
    (new Process(['git', 'init', '--quiet', '--bare', '-b', 'main', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'main', $root.'/work']))->mustRun();
    $git($root.'/work', 'remote', 'add', 'origin', $root.'/origin.git');
    $git($root.'/work', 'commit', '--quiet', '--allow-empty', '-m', 'Inherited [skip ci]');
    $head = $git($root.'/work', 'rev-parse', 'HEAD');
    $tree = $git($root.'/work', 'rev-parse', 'HEAD^{tree}');
    $group->taskable->update(['checkout_path' => $root.'/work', 'starting_commit' => $head]);
    $runner = Mockery::mock(TaskCheckRunner::class);
    $runner->shouldReceive('snapshot')->andReturnUsing(fn (): TaskWorkspaceSnapshot => new TaskWorkspaceSnapshot($git($root.'/work', 'rev-parse', 'HEAD'), $git($root.'/work', 'write-tree'), commitTree: $git($root.'/work', 'rev-parse', 'HEAD^{tree}')));
    $runner->shouldReceive('start')->once()->andReturn(new TaskCheckProcess(4001, 'identity', $head, $tree));
    $runner->shouldReceive('read')->once()->andReturn(TaskCheckReading::finished(0, $head, $tree, [], 'Local check passed.'));
    app()->instance(TaskCheckRunner::class, $runner);
    $transport = new class($reply) implements SshExecutor
    {
        public function __construct(private string $reply) {}

        public int $lostCommits = 0;

        public int $commands = 0;

        private array $receipts = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return app(RemoteWorkspaceOperationJournal::class)->execute($connection, $command,
                fn (RemoteCommand $wrapped): CommandResult => $this->dispatch($connection, $wrapped));
        }

        private function dispatch(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands++;
            // Model only the remote managed-process receipt protocol. Git and the production
            // Gateway journal/reconciliation still run; no local system services are touched.
            $mode = $command->arguments[3] ?? null;
            if ($mode === 'probe') {
                return new CommandResult(0, json_encode($this->receipts[$command->arguments[4]], JSON_THROW_ON_ERROR), '', 0, false);
            }
            $payload = $mode === 'run' ? new RemoteCommand(array_slice($command->arguments, 7), input: $command->input, protectedInput: $command->protectedInput, maxOutputBytes: $command->maxOutputBytes, output: $command->output, cancelled: $command->cancelled, timeout: $command->timeout, terminateGraceSeconds: $command->terminateGraceSeconds) : $command;
            $result = (new StreamingLocalShellSshExecutor)->execute($connection, $payload);
            if ($mode === 'run') {
                $this->receipts[$command->arguments[4]] = ['operation' => $command->arguments[4], 'binding' => $command->arguments[5], 'state' => 'finished', 'exit_code' => $result->exitCode];
            }
            // The remote transaction completed, but its reply was lost before TaskCheck.commit_sha persisted.
            if ($this->lostCommits === 0 && str_contains($command->input ?? '', 'git update-ref --stdin')) {
                expect($result->exitCode)->toBe(0);
                $this->lostCommits++;

                return new CommandResult(match ($this->reply) {
                    'ssh' => 255, 'timeout' => 124, default => 0
                }, '', '', 0, $this->reply === 'timeout');
            }

            return $result;
        }
    };
    app()->instance(SshExecutor::class, $transport);
    $this->mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    $this->mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    app()->bind(TaskWorkspaceSigner::class, RemoteTaskWorkspaceSigner::class);
    $operation = check_operation_start($group, 'handoff', 'hosted.recovered');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    expect($operation->refresh()->error_code)->toBeNull();
    expect($operation->status)->toBe(OperationStatus::Waiting);
    expect($operation->current_step)->toBe('commit');
    $check = TaskCheck::query()->sole();
    expect($check->commit_sha)->toBeNull();
    $committed = $git($root.'/work', 'rev-parse', 'HEAD');
    expect($committed)->not->toBe($head);
    expect($git($root.'/work', 'rev-parse', 'refs/orbit/check-operations/'.$operation->id))->toBe($committed);
    hosted_operation_read($operation);
    expect($operation->refresh()->current_step)->toBe('handoff_push');
    expect($check->refresh()->commit_sha)->toBe($committed);
    expect($git($root.'/work', 'rev-list', '--count', 'HEAD'))->toBe('2');
    expect($git($root.'/work', 'log', '-1', '--format=%B'))->not->toMatch('/\[skip ci\]|skip-checks:/i');
    $effects->snapshots = [fn (string $sha): array => ['runs' => [hosted_operation_run($sha)], 'suites' => []]];
    // Run the real VM push and interrupt after the remote transfer, before the VM's terminal DB write.
    GitRemoteRedirect::global($root.'/origin.git', 'https://github.com/acme/shop.git');
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_local_test'], 201),
    ]);
    try {
        $publisher = app(GitHubTaskPullRequestPublisher::class);
        $job = Queue::pushed(PublishTaskVmCommit::class)->sole();
        $job->handle($publisher);
        $vm = TaskVm::forGroup($group);
        expect($vm->publish_state)->toBe(TaskVmPublishState::Pushed);
        $transfers = $transport->commands;
        expect($transfers)->toBeGreaterThan(0);
        // Reproduce the persistent state of a worker lost between pushing and storing the reply.
        $vm->update(['publish_state' => TaskVmPublishState::Queued, 'publish_started_at' => now()]);
        $job->handle($publisher);
        expect($vm->refresh()->publish_state)->toBe(TaskVmPublishState::Pushed);
        expect($transport->commands)->toBe($transfers);
        expect($git($root.'/origin.git', 'rev-parse', 'refs/heads/task-'.$group->id.'-handoff'))->toBe($committed);
        hosted_operation_read($operation);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result['outcome'])->toBe('passed');
        expect($operation->result['commit'])->toBe($committed);
        expect(check_operation_start($group, 'handoff', 'hosted.recovered')->id)->toBe($operation->id);
        app(OperationRunner::class)->run($operation->id);
        expect($git($root.'/work', 'rev-list', '--count', 'HEAD'))->toBe('2');
        expect($transport->commands)->toBe($transfers);
        Queue::assertPushed(PublishTaskVmCommit::class, 1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/pulls') || str_contains($request->url(), '/actions'));
    } finally {
        GitRemoteRedirect::clear();
    }
})->with(['empty', 'ssh', 'timeout']);

it('hosted handoff CI operation never requeues a failed push and retains confirmed push evidence on cancel', function (bool $failed): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $vm = TaskVm::forGroup($group);
    $vm->update(['publish_state' => $failed ? TaskVmPublishState::Failed : TaskVmPublishState::Pushed, 'publish_started_at' => now(), 'publish_error' => $failed ? 'The remote rejected the push.' : null]);
    if ($failed) {
        hosted_operation_read($operation);
        hosted_operation_read($operation);
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->state['ci']['state'])->toBe('unreadable');
        expect($operation->state['ci']['fault'])->toBe('infrastructure');
        expect($vm->refresh()->publish_state)->toBe(TaskVmPublishState::Failed);
    }
    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($operation->refresh()->result['ci']['pushed_at'] !== null)->toBe(! $failed);
    expect($operation->result['ci']['commit'])->toBe(TaskCheck::query()->sole()->commit_sha);
    Queue::assertPushed(PublishTaskVmCommit::class, 1);
    expect($runner->starts)->toBe(1);
    expect($effects->reads)->toBe(0);
})->with([true, false]);

it('hosted handoff CI operation shares complete evidence guards between reviewer and publisher at admission and execution', function (string $damage, string $consumer, bool $queued, bool $policy): void {
    [$group, $runner, $effects] = hosted_operation_fixture($policy);
    $effects->snapshots = [fn (string $sha): array => ['runs' => [hosted_operation_run($sha)], 'suites' => []]];
    $operation = check_operation_start($group, 'handoff');
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    if ($policy) {
        hosted_operation_pushed($group, $operation);
        hosted_operation_read($operation);
    }
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['outcome'])->toBe('passed');
    $check = TaskCheck::query()->sole();
    $url = '/api/v1/task-groups/'.$group->id.($consumer === 'reviewer' ? '/turn' : '/publish');
    $body = $consumer === 'reviewer' ? ['subtask' => $check->task_id, 'thread' => 'new', 'role' => 'reviewer', 'check' => $check->id, 'prompt' => 'Review exactly this commit.'] : ['check' => $check->id, 'pull_request' => ['title' => 'Title', 'body' => 'Body']];
    if ($queued) {
        $id = $this->postJson($url, $body)->assertAccepted()->json('data.id');
    }
    $result = $operation->result;
    $state = $operation->state;
    match ($damage) {
        'absent' => $operation->delete(),
        'owner' => $operation->update(['status' => OperationStatus::Cancelled]),
        'head' => $operation->update(['result' => [...$result, 'head' => str_repeat('9', 40)]]),
        'commit' => $check->update(['commit_sha' => str_repeat('d', 40)]),
        'tree' => $check->update(['tree_after' => str_repeat('f', 40)]),
        'check' => $operation->update(['result' => [...$result, 'check_id' => $check->id + 1000]]),
        'branch' => $operation->update(['state' => [...$state, 'ci' => [...($state['ci'] ?? []), 'branch' => 'task-'.$group->id]]]),
        'ci' => $operation->update(['state' => [...$state, 'ci' => [...($state['ci'] ?? []), 'state' => 'pending']]]),
        'deliverable' => $check->task()->firstOrFail()->update(['deliverables' => [['id' => 'new-file', 'type' => 'file', 'path' => 'required.txt']]]),
    };
    if ($queued) {
        app(OperationRunner::class)->run($id);
        $consumerOperation = Operation::query()->findOrFail($id);
        expect($consumerOperation->status)->toBe(OperationStatus::Failed);
        expect($consumerOperation->error_code)->toBe('tasks.commit_not_checked');
    } else {
        $this->postJson($url, $body)->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
    }
    expect($effects->reads)->toBe($policy ? 1 : 0);
    expect($runner->starts)->toBe(1);
    Queue::assertPushed(PublishTaskVmCommit::class, $policy ? 1 : 0);
})->with(['absent', 'owner', 'head', 'commit', 'tree', 'check', 'branch', 'ci', 'deliverable'])->with(['reviewer', 'publisher'])->with(['admission' => false, 'queued execution' => true])->with(['required CI' => true, 'no policy' => false]);

it('hosted handoff CI operation retains the candidate when cancellation arrives during signing without dispatching a push', function (): void {
    [$group, $runner, $effects] = hosted_operation_fixture();
    $operation = check_operation_start($group, 'handoff', 'hosted.cancel-signing');
    $effects->onCommit = function () use ($operation): void {
        test()->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    };
    app(OperationRunner::class)->run($operation->id);
    hosted_operation_read($operation);
    $check = TaskCheck::query()->sole();
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($check->commit_sha)->not->toBeNull();
    expect($operation->state['ci']['commit'])->toBe($check->commit_sha);
    expect($operation->result['commit'])->toBe($check->commit_sha);
    expect($operation->result['ci'])->toBe($operation->state['ci']);
    expect($operation->result['ci']['pushed_at'])->toBeNull();
    expect($operation->lock_key)->toBeNull();
    Queue::assertNotPushed(PublishTaskVmCommit::class);
    expect(check_operation_start($group, 'handoff', 'hosted.cancel-signing')->id)->toBe($operation->id);
    expect($runner->starts)->toBe(1);
    expect($effects->commits)->toHaveCount(1);
});
