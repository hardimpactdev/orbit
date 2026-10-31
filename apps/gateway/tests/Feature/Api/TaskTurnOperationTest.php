<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewReset;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

beforeEach(function (): void {
    enable_operation_queue();
    $this->travelTo(now()->startOfSecond());
});

/** @return array{Task, Task} */
function turn_operation_group(string $orchestration = 'external'): array
{
    $node = test()->markAsGateway(Node::query()->create(['name' => 'turn-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.91', 'public_ssh_host' => '192.0.2.91']));
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Turn', 'slug' => 'turn', 'repository_url' => 'https://github.com/acme/turn.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Turn', 'brief' => 'Orchestrated.', 'status' => 'running', 'orchestration' => $orchestration, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'gpt-5.6-luna', 'reviewer_model' => 'gpt-5.6-luna']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One.', 'status' => 'running', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/turn', 'status' => 'source_resolved']);
    $group->taskable()->associate($workspace)->save();
    test_task_vm($group, $node);

    return [$group->refresh(), $task];
}

/** @return array{subtask: int, role: string, prompt: string, thread: string} */
function turn_operation_input(Task $task): array
{
    return ['subtask' => $task->id, 'role' => 'implementer', 'prompt' => 'Implement exactly this prompt.', 'thread' => 'new'];
}

function turn_operation_fakes(?FakeTaskTurnReceipts $receipts = null): FakeAgentDriver
{
    $driver = new FakeAgentDriver('pi');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    app()->instance(TaskTurnReceipts::class, $receipts ?? new FakeTaskTurnReceipts);
    test()->mock(TaskWorkspaceMcp::class)->shouldReceive('installWhenMissing')->andReturnTrue();

    return $driver;
}

/** @param array<string, mixed> $input */
function turn_operation_start(Task $group, Task $task, array $input = [], ?string $key = null): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), ...$input], $key === null ? [] : ['Idempotency-Key' => $key])->assertAccepted();

    return Operation::query()->findOrFail($response->json('data.id'));
}

function turn_operation_finish(Operation $operation, FakeAgentDriver $driver, AgentThreadState $state = AgentThreadState::Done, ?string $error = null): void
{
    $driver->observation = new AgentObservation(state: $state, error: $error, turnId: $operation->idempotency_key ?? 'operation-'.$operation->id, tokens: 123, inputTokens: 80, cachedInputTokens: 20, outputTokens: 23, modelCalls: 2, peakContextTokens: 100);
    test()->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $operation->refresh();
}

function turn_operation_check(Task $task): TaskCheck
{
    $check = $task->checks()->create(['kind' => 'handoff', 'status' => 'passed', 'commit_sha' => str_repeat('a', 40), 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'pid' => 10, 'process_started' => 'start', 'started_at' => now()]);
    task_checked_operation($check);

    return $check;
}

describe('tasks.turn', function (): void {

    it('refuses an ended group with 409', function (): void {
        [$group, $task] = turn_operation_group();
        $group->update(['status' => TaskGroupStatus::Completed]);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertConflict()->assertJsonPath('error.code', 'tasks.group_closed');
        expect(Operation::query()->count())->toBe(0);
    });

    it('refuses a missing workspace with 409', function (): void {
        [$group, $task] = turn_operation_group();
        $group->taskable()->dissociate()->save();

        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    });

    it('refuses a subtask outside the group with 404', function (): void {
        [$group, $task] = turn_operation_group();
        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'subtask' => $group->id])->assertNotFound();
    });

    it('refuses a later subtask with 409', function (): void {
        [$group] = turn_operation_group();
        $later = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Later', 'brief' => 'Later.', 'status' => 'todo']);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($later))->assertConflict()->assertJsonPath('error.code', 'tasks.subtask_not_current');
    });

    it('refuses a missing or wrong-role thread with 422', function (string $thread): void {
        [$group, $task] = turn_operation_group();
        $existing = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'existing', 'role' => 'reviewer']);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'thread' => $thread === 'wrong-role' ? (string) $existing->id : '99999'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.thread_role_mismatch');
    })->with(['missing', 'wrong-role']);

    it('refuses a model on a continued thread with 422', function (): void {
        [$group, $task] = turn_operation_group();
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'existing', 'role' => 'implementer', 'model' => 'kept']);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'thread' => (string) $thread->id, 'model' => 'replacement'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.model_fixed');
    });

    it('refuses Claude with 422', function (string $model): void {
        [$group, $task] = turn_operation_group();
        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'model' => $model])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.model_refused');
    })->with(['claude-sonnet', 'anthropic/anything', 'other/claude-opus']);

    it('stores a receipt, workspace evidence and metrics without changing board state', function (): void {
        [$group, $task] = turn_operation_group();
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('ready_for_review', 'Implemented.', deliverables: ['done' => 'Verified.'])]);
        $driver = turn_operation_fakes($receipts);
        $operation = turn_operation_start($group, $task, ['model' => 'chosen-model'], 'implement.c1');

        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        turn_operation_finish($operation, $driver);

        expect($operation->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result['receipt']['outcome'])->toBe('ready_for_review');
        expect($operation->result['receipt']['deliverables'])->toBe(['done' => 'Verified.']);
        expect($operation->result['before']['head'])->toBe(str_repeat('a', 40));
        expect($operation->result['after']['tree'])->toBe(str_repeat('b', 40));
        expect($operation->result['metrics']['tokens'])->toBe(123);
        expect($receipts->cleared)->toHaveCount(1);
        expect(AgentThread::query()->sole()->model)->toBe('chosen-model');
        expect(AgentThread::query()->sole()->task_id)->toBe($task->id);
        expect($task->refresh()->status)->toBe(TaskStatus::Running);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
        expect($driver->calls[1]['key'])->toBe('implement.c1');
        expect($driver->calls[1]['message'])->toContain('Do not fetch or push.');
        expect($driver->calls[1]['message'])->toContain('Implement exactly this prompt.');
        expect($driver->calls[1]['message'])->not->toContain('rubric');
        expect($driver->calls[1]['message'])->not->toContain('reminder');
        expect(TaskComment::query()->count())->toBe(0);
    });

    it('returns null for a turn without a receipt', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes(new FakeTaskTurnReceipts([null]));
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        turn_operation_finish($operation, $driver);

        expect($operation->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result['receipt'])->toBeNull();
    });

    it('keeps the recorded turn owned after a terminal subtask update and stores its receipt', function (string $status): void {
        [$group, $task] = turn_operation_group();
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('ready_for_review', 'Recorded turn result.')]);
        $driver = turn_operation_fakes($receipts);
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        $thread = AgentThread::query()->sole();
        $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();

        $this->patchJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id, ['status' => $status])->assertOk();
        turn_operation_finish($operation, $driver, AgentThreadState::Working);

        expect($operation->status)->toBe(OperationStatus::Waiting);
        expect($operation->lock_key)->toBe('task_group:'.$group->id);
        expect($lease->refresh()->status)->toBe('held');
        expect($lease->thread_id)->toBe($thread->id);
        expect($thread->refresh()->state)->toBe(AgentThreadState::Working);
        expect($receipts->reads())->toBe(0);

        turn_operation_finish($operation, $driver);

        expect($operation->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result['thread'])->toBe($thread->id);
        expect($operation->result['receipt']['summary'])->toBe('Recorded turn result.');
        expect($receipts->cleared)->toHaveCount(1);
        expect($operation->lock_key)->toBeNull();
        expect($lease->refresh()->status)->toBe('released');
        expect($task->refresh()->status->value)->toBe($status);
        expect(array_column($driver->calls, 'operation'))->toBe(['create', 'send']);
    })->with(['completed', 'failed']);

    it('stores a blocked receipt without starting a consult or question or assistance', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes(new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Need input.', 'Which contract?', cause: 'contract_gap')]));
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        turn_operation_finish($operation, $driver);

        expect($operation->result['receipt']['question'])->toBe('Which contract?');
        expect($operation->result['receipt']['cause'])->toBe('contract_gap');
        expect(AgentThread::query()->count())->toBe(1);
        expect($driver->calls)->toHaveCount(2);
        expect(TaskComment::query()->count())->toBe(0);
        expect($task->refresh()->assistance_requested)->toBeFalse();
        expect($group->refresh()->assistance_requested)->toBeFalse();
    });

    it('continues a thread with its existing model', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes();
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id, 'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'existing', 'role' => 'implementer', 'model' => 'kept']);
        $driver->observation = new AgentObservation(state: AgentThreadState::Done, turnId: 'earlier');
        $operation = turn_operation_start($group, $task, ['thread' => (string) $thread->id]);
        app(OperationRunner::class)->run($operation->id);
        turn_operation_finish($operation, $driver);

        expect($operation->result['thread'])->toBe($thread->id);
        expect($thread->refresh()->model)->toBe('kept');
        expect(array_column($driver->calls, 'operation'))->toBe(['send']);
    });

    it('requires a passed handoff check and its unchanged HEAD', function (): void {
        [$group, $task] = turn_operation_group();
        turn_operation_fakes();
        $input = [...turn_operation_input($task), 'role' => 'reviewer', 'check' => 9999];
        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', $input)->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
        $check = turn_operation_check($task);
        $check->update(['commit_sha' => str_repeat('c', 40)]);
        $owner = Operation::query()->where('kind', 'tasks.check')->sole();
        $owner->update(['result' => [...$owner->result, 'commit' => $check->commit_sha]]);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...$input, 'check' => $check->id])->assertConflict()->assertJsonPath('error.code', 'tasks.head_moved');
    });

    it('records and removes reviewer changes and restores the checked commit', function (string $ending): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes(new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('approved')]));
        $check = turn_operation_check($task);
        $diff = ['files' => [['path' => 'oops.txt', 'insertions' => 1, 'deletions' => 0]], 'diff' => '+oops', 'files_complete' => true, 'diff_available' => true, 'summary' => ['files' => 1, 'insertions' => 1, 'deletions' => 0]];
        $this->mock(TaskReviewDiff::class)->shouldReceive('read')->once()->andReturn($diff);
        $this->mock(TaskReviewReset::class)->shouldReceive('reset')->once()->withArgs(fn (Instance $instance, string $commit): bool => $instance->id === $group->taskable_id && $commit === $check->commit_sha)->andReturnUsing(function (): void {
            app(TaskCheckRunner::class)->tree = str_repeat('b', 40);
        });
        $operation = turn_operation_start($group, $task, ['role' => 'reviewer', 'check' => $check->id]);
        app(OperationRunner::class)->run($operation->id);
        app(TaskCheckRunner::class)->tree = str_repeat('c', 40);
        if ($ending === 'cancelled') {
            $driver->supportsInterruption = true;
            $driver->duringInterrupt = function () use ($driver, $operation): void {
                $driver->observation = new AgentObservation(state: AgentThreadState::Failed, turnId: 'operation-'.$operation->id, error: 'Interrupted.');
            };
            $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
            $operation->refresh();
        } else {
            turn_operation_finish($operation, $driver, AgentThreadState::from($ending), $ending === 'failed' ? 'Failed.' : null);
        }

        expect($operation->status->value)->toBe(match ($ending) {
            'done' => 'succeeded', default => $ending
        });
        expect($operation->result['difference'])->toBe($diff);
        expect($operation->result['after']['tree'])->toBe(str_repeat('c', 40));
        expect($operation->result['after_reset']['tree'])->toBe(str_repeat('b', 40));
    })->with(['done', 'failed', 'cancelled']);

    it('records and resets reviewer changes after a terminal subtask update', function (string $status): void {
        [$group, $task] = turn_operation_group();
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('approved')]);
        $driver = turn_operation_fakes($receipts);
        $check = turn_operation_check($task);
        $diff = ['diff' => '+reviewer change', 'files' => [['path' => 'oops.txt', 'insertions' => 1, 'deletions' => 0]]];
        $this->mock(TaskReviewDiff::class)->shouldReceive('read')->once()->withArgs(fn (Instance $instance, string $commit): bool => $instance->id === $group->taskable_id && $commit === $check->commit_sha)->andReturn($diff);
        $this->mock(TaskReviewReset::class)->shouldReceive('reset')->once()->withArgs(fn (Instance $instance, string $commit): bool => $instance->id === $group->taskable_id && $commit === $check->commit_sha)->andReturnUsing(function () use ($group): void {
            expect(TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('status', 'held')->sole()->kind)->toBe('turn_result');
            app(TaskCheckRunner::class)->head = str_repeat('a', 40);
            app(TaskCheckRunner::class)->tree = str_repeat('b', 40);
        });
        $operation = turn_operation_start($group, $task, ['role' => 'reviewer', 'check' => $check->id]);
        app(OperationRunner::class)->run($operation->id);
        $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
        app(TaskCheckRunner::class)->head = str_repeat('c', 40);
        app(TaskCheckRunner::class)->tree = str_repeat('d', 40);

        $this->patchJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id, ['status' => $status])->assertOk();
        turn_operation_finish($operation, $driver, AgentThreadState::Working);

        expect($operation->status)->toBe(OperationStatus::Waiting);
        expect($operation->lock_key)->toBe('task_group:'.$group->id);
        expect($lease->refresh()->status)->toBe('held');
        expect($operation->result)->toBeNull();
        expect($receipts->reads())->toBe(0);
        expect(app(TaskCheckRunner::class)->tree)->toBe(str_repeat('d', 40));

        turn_operation_finish($operation, $driver);

        expect($operation->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result['receipt']['outcome'])->toBe('approved');
        expect($receipts->cleared)->toHaveCount(1);
        expect($operation->result['difference'])->toBe($diff);
        expect($operation->result['after']['head'])->toBe(str_repeat('c', 40));
        expect($operation->result['after']['tree'])->toBe(str_repeat('d', 40));
        expect($operation->result['after_reset'])->toBe(['head' => $check->commit_sha, 'tree' => str_repeat('b', 40)]);
        expect($operation->lock_key)->toBeNull();
        expect($lease->refresh()->status)->toBe('released');
        expect($task->refresh()->status->value)->toBe($status);
        expect(array_column($driver->calls, 'operation'))->toBe(['create', 'send']);
    })->with(['completed', 'failed']);

    it('continues a reviewer for a consult and never resets its changes', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes(new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('answered', cause: 'scope')]));
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id, 'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'reviewer', 'role' => 'reviewer', 'model' => 'kept']);
        $driver->observation = new AgentObservation(state: AgentThreadState::Done, turnId: 'earlier');
        $this->mock(TaskReviewDiff::class)->shouldReceive('read')->once()->andReturn(['diff' => '+consult change']);
        $this->mock(TaskReviewReset::class)->shouldNotReceive('reset');
        $operation = turn_operation_start($group, $task, ['role' => 'consult', 'thread' => (string) $thread->id]);
        app(OperationRunner::class)->run($operation->id);
        app(TaskCheckRunner::class)->tree = str_repeat('c', 40);
        turn_operation_finish($operation, $driver);

        expect($operation->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result['difference']['diff'])->toBe('+consult change');
        expect($operation->result['after_reset'])->toBeNull();
        expect(app(TaskTurnReceipts::class)->modes)->toBe(['consult']);
    });

    it('keeps waiting while Durable resumes or retries the working turn', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes();
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        turn_operation_finish($operation, $driver, AgentThreadState::Working, 'Durable retry 1: overloaded; resume after restart.');

        expect($operation->status)->toBe(OperationStatus::Waiting);
        expect(AgentThread::query()->sole()->state)->toBe(AgentThreadState::Working);
        expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(1);
        expect($driver->calls)->toHaveCount(2);
    });

    it('fails a terminal turn and raises no assistance', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes(new FakeTaskTurnReceipts([null]));
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        turn_operation_finish($operation, $driver, AgentThreadState::Failed, 'Durable crash-loop parked after five restarts.');

        expect($operation->status)->toBe(OperationStatus::Failed);
        expect($operation->result['error'])->toBe('Durable crash-loop parked after five restarts.');
        expect($group->refresh()->assistance_requested)->toBeFalse();
        expect($task->refresh()->assistance_requested)->toBeFalse();
        expect(TaskComment::query()->count())->toBe(0);
    });

    it('cancels a waiting turn after its interrupt is confirmed', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes();
        $driver->supportsInterruption = true;
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        $driver->duringInterrupt = function () use ($driver, $operation): void {
            $driver->observation = new AgentObservation(state: AgentThreadState::Failed, turnId: 'operation-'.$operation->id, error: 'Interrupted.');
        };

        $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect(array_column($driver->calls, 'operation'))->toBe(['create', 'send', 'interrupt']);
        expect($task->refresh()->status)->toBe(TaskStatus::Running);
    });

    it('records an unconfirmed cancel before answering 502 and retries the stop on its next read', function (bool $subtask): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes();
        $driver->supportsInterruption = true;
        $operation = turn_operation_start($group, $task);
        app(OperationRunner::class)->run($operation->id);
        $driver->observation = new AgentObservation(state: AgentThreadState::Working, turnId: 'operation-'.$operation->id);

        $path = $subtask ? '/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id.'/cancel' : '/api/v1/operations/'.$operation->id.'/cancel';
        $this->call('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertStatus(502)->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->cancel_requested_at)->not->toBeNull();
        expect($task->refresh()->status)->toBe(TaskStatus::Running);
        $driver->duringInterrupt = function () use ($driver, $operation): void {
            $driver->observation = new AgentObservation(state: AgentThreadState::Failed, turnId: 'operation-'.$operation->id);
        };
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    })->with(['operation cancel' => false, 'subtask cancel' => true]);

    it('replays the same keyed turn before validation and refuses other group work', function (): void {
        [$group, $task] = turn_operation_group();
        $driver = turn_operation_fakes();
        $operation = turn_operation_start($group, $task, key: 'turn.replay');
        app(OperationRunner::class)->run($operation->id);
        turn_operation_finish($operation, $driver);
        $task->update(['status' => 'completed']);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task), ['Idempotency-Key' => 'turn.replay'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
        expect(Operation::query()->count())->toBe(1);
        expect($driver->calls)->toHaveCount(2);
    });
});

it('requires Gateway access and an enabled Tasks extension for turns', function (): void {
    [$group, $task] = turn_operation_group();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.254']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.91']);
    app(TaskExtensionState::class)->disable();
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    expect(Operation::query()->count())->toBe(0);
});

it('validates required turn fields before recording work', function (): void {
    [$group] = turn_operation_group();
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/turn', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertUnprocessable()->assertJsonValidationErrors(['subtask', 'role', 'prompt', 'thread'], 'error.details');
    expect(Operation::query()->count())->toBe(0);
});

it('holds the group across task operation kinds', function (): void {
    [$group, $task] = turn_operation_group();
    $holder = app(OperationStore::class)->create('tasks.check', 'task_group', $group->id, null, [], 'test', null);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertConflict()->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $holder->id);
});

it('refuses a thread of a different group with 422', function (): void {
    [$group, $task] = turn_operation_group();
    $other = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other.', 'status' => 'running']);
    $thread = AgentThread::query()->create(['task_group_id' => $other->id, 'driver' => 'pi', 'runtime_key' => 'node:1', 'external_id' => 'other-thread', 'role' => 'implementer']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'thread' => (string) $thread->id])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.thread_role_mismatch');
});

it('refuses a handoff check that did not pass or has no commit', function (string $kind, string $status, ?string $commit): void {
    [$group, $task] = turn_operation_group();
    $check = turn_operation_check($task);
    $check->update(['kind' => $kind, 'status' => $status, 'commit_sha' => $commit]);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'role' => 'reviewer', 'check' => $check->id])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
})->with([['baseline', 'passed', str_repeat('a', 40)], ['handoff', 'failed', str_repeat('a', 40)], ['handoff', 'passed', null]]);

it('waits for a parked VM resume before creating a thread', function (): void {
    [$group, $task] = turn_operation_group();
    $driver = turn_operation_fakes();
    $vm = TaskVm::forGroup($group);
    Queue::fake([ResumeTaskVm::class]);
    $vm->update(['state' => TaskVmState::Parked]);
    $operation = turn_operation_start($group, $task);
    app(OperationRunner::class)->run($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->current_step)->toBe('turn_workspace');
    expect($driver->calls)->toBe([]);
    Queue::assertPushed(ResumeTaskVm::class);
    $vm->update(['state' => TaskVmState::Ready]);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->refresh()->current_step)->toBe('turn');
    expect($driver->calls)->toHaveCount(2);
});

it('rechecks the reviewer HEAD under its workspace lease before sending', function (): void {
    [$group, $task] = turn_operation_group();
    $driver = turn_operation_fakes();
    $check = turn_operation_check($task);
    $operation = turn_operation_start($group, $task, ['role' => 'reviewer', 'check' => $check->id]);
    app(TaskCheckRunner::class)->head = str_repeat('c', 40);
    app(OperationRunner::class)->run($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe('tasks.head_moved');
    expect($driver->calls)->toBe([]);
});

it('fails an unavailable model with the Pi error without raising assistance', function (): void {
    [$group, $task] = turn_operation_group();
    turn_operation_fakes();
    $driver = new class('pi') extends FakeAgentDriver
    {
        public function create(AgentThreadStart $intent): string
        {
            $exception = new AgentDriverException('The model is unavailable.');
            $exception->errorCode = 'model.unavailable';
            $exception->turnRejected = true;
            throw $exception;
        }
    };
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $operation = turn_operation_start($group, $task, ['model' => 'missing-model']);
    app(OperationRunner::class)->run($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe('model.unavailable');
    expect($group->refresh()->assistance_requested)->toBeFalse();
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(0);
});

it('preserves the provider category when Durable exhausts its budget', function (): void {
    [$group, $task] = turn_operation_group();
    $driver = turn_operation_fakes(new FakeTaskTurnReceipts([null]));
    $operation = turn_operation_start($group, $task);
    app(OperationRunner::class)->run($operation->id);
    $driver->observation = new AgentObservation(state: AgentThreadState::Failed, error: 'Retry budget exhausted.', providerError: 'overloaded', turnId: 'operation-'.$operation->id);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe('overloaded');
    expect($operation->result['error'])->toBe('Retry budget exhausted.');
    expect(TaskComment::query()->count())->toBe(0);
});

it('does not interrupt another turn when cancelled while queued for its workspace', function (bool $continued): void {
    [$group, $task] = turn_operation_group();
    $driver = turn_operation_fakes();
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id, 'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'prior', 'role' => 'implementer', 'model' => 'kept']);
    $driver->observation = new AgentObservation(state: AgentThreadState::Working, turnId: 'prior-turn');
    app(TaskWorkspaceLease::class)->reconcile($group);
    app(TaskWorkspaceLease::class)->dispatch($thread, key: 'prior-turn', message: 'Earlier work.');
    $operation = turn_operation_start($group, $task, ['thread' => $continued ? (string) $thread->id : 'new']);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->current_step)->toBe('prepare_turn');

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($driver->calls)->toBe([]);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->dispatch_key)->toBe('prior-turn');
    expect(AgentThread::query()->count())->toBe(1);
})->with([false, true]);

it('refuses a passed handoff check from a different subtask', function (): void {
    [$group, $task] = turn_operation_group();
    $later = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Later', 'brief' => 'Later.', 'status' => 'todo']);
    $check = turn_operation_check($later);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [...turn_operation_input($task), 'role' => 'reviewer', 'check' => $check->id])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
});

it('confirms the stop when cancellation arrives inside send', function (): void {
    [$group, $task] = turn_operation_group();
    $driver = turn_operation_fakes();
    $driver->supportsInterruption = true;
    $operation = turn_operation_start($group, $task);
    $driver->beforeTurn = function (string $prompt) use ($operation): void {
        if ($prompt !== '') {
            app(CancelOperationAction::class)->execute($operation->fresh());
        }
    };
    $driver->duringInterrupt = function () use ($driver, $operation): void {
        $driver->observation = new AgentObservation(state: AgentThreadState::Failed, turnId: 'operation-'.$operation->id, error: 'Interrupted.');
    };
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->cancel_requested_at)->not->toBeNull();
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect(array_column($driver->calls, 'operation'))->toBe(['create', 'send', 'interrupt']);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(0);
});

it('collects evidence and resets a reviewer before subtask cancellation', function (): void {
    [$group, $task] = turn_operation_group();
    $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Interrupted review.', 'Which scope?', cause: 'scope')]);
    $driver = turn_operation_fakes($receipts);
    $driver->supportsInterruption = true;
    $check = turn_operation_check($task);
    $this->mock(TaskReviewDiff::class)->shouldReceive('read')->once()->andReturn(['diff' => '+reviewer scratch']);
    $this->mock(TaskReviewReset::class)->shouldReceive('reset')->once()->withArgs(fn (Instance $instance, string $commit): bool => $commit === $check->commit_sha);
    $operation = turn_operation_start($group, $task, ['role' => 'reviewer', 'check' => $check->id]);
    app(OperationRunner::class)->run($operation->id);
    $driver->duringInterrupt = function () use ($driver, $operation): void {
        $driver->observation = new AgentObservation(state: AgentThreadState::Failed, turnId: 'operation-'.$operation->id, error: 'Interrupted.');
    };
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->result['receipt']['outcome'])->toBe('blocked');
    expect($operation->result['difference']['diff'])->toBe('+reviewer scratch');
    expect($receipts->cleared)->toHaveCount(1);
    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(0);
});
