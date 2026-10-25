<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScriptRunner;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Tasks\ScriptTaskGroupRequest;
use Tests\Support\FakeTaskScriptRunner;

beforeEach(function (): void {
    enable_operation_queue();
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskScriptRunner::class, new FakeTaskScriptRunner);
    $this->travelTo('2026-10-25 12:00:00');
});

function script_operation_group(): Task
{
    $group = tick_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'external']);
    $group->refresh();
    $group->tasks()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_group_id', $group->id)->delete();
    test()->markAsGateway($group->taskable->node);
    test()->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);

    return $group->refresh();
}

function script_operation_start(Task $group, array $overrides = [], string $key = 'script-one'): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/script', array_replace([
        'subtask' => $group->tasks()->firstOrFail()->id, 'script' => 'scripts/dependencies', 'args' => ['routine', '$(touch nope); literal'],
    ], $overrides), ['Idempotency-Key' => $key])->assertAccepted()->assertJsonPath('data.kind', 'tasks.script');

    return Operation::query()->findOrFail($response->json('data.id'));
}

function script_operation_finish(Operation $operation): void
{
    app(OperationRunner::class)->run($operation->id);
    test()->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $operation->refresh();
}

it('returns script exit and changed tree without a check, agent, commit or board transition', function (int $exit): void {
    $group = script_operation_group();
    $status = $group->status;
    $runner = app(TaskScriptRunner::class);
    $runner->completion['exit_code'] = $exit;
    $runner->changedTree = str_repeat('e', 40);
    $operation = script_operation_start($group);

    script_operation_finish($operation);

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toMatchArray(['outcome' => $exit === 0 ? 'passed' : 'failed', 'exit_code' => $exit, 'changed' => true])
        ->and($runner->input['args'])->toBe(['routine', '$(touch nope); literal'])
        ->and($runner->starts)->toBe(1)
        ->and(TaskCheck::query()->count())->toBe(0)->and(AgentThread::query()->count())->toBe(0)
        ->and($group->fresh()->status)->toBe($status)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'script')->sole()->status)->toBe('released');
})->with([0, 23]);

it('recovers a lost start reply and replays after the child ends without dispatching again', function (): void {
    $group = script_operation_group();
    $runner = app(TaskScriptRunner::class);
    $runner->loseStartReply = true;
    $operation = script_operation_start($group);
    script_operation_finish($operation);
    $group->tasks()->update(['status' => 'completed']);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/script', $operation->input, ['Idempotency-Key' => 'script-one'])
        ->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'succeeded');

    expect($runner->starts)->toBe(1)->and($operation->result['changed'])->toBeFalse();
});

it('redacts and bounds output before retaining terminal evidence', function (): void {
    $group = script_operation_group();
    $runner = app(TaskScriptRunner::class);
    $runner->bytes = str_repeat("log line\n", 10000)."Authorization: Bearer super-secret-value\n";
    $operation = script_operation_start($group);
    script_operation_finish($operation);

    expect(strlen($operation->result['output']))->toBeLessThanOrEqual(16384);
    expect($operation->result['output_truncated'])->toBeTrue();
    expect(json_encode($operation->state))->not->toContain('super-secret-value');
});

it('keeps unknown process ownership fenced and confirms cancellation before release', function (): void {
    $group = script_operation_group();
    $runner = app(TaskScriptRunner::class);
    $runner->terminal = false;
    $operation = script_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $runner->uncertain = true;
    app(CancelOperationAction::class)->execute($operation->refresh());
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->fresh()->status)->toBe(OperationStatus::Waiting)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'script')->sole()->status)->toBe('held');

    $runner->uncertain = false;
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'script')->sole()->status)->toBe('released')
        ->and($runner->starts)->toBe(1);
});

it('requires a key and rejects caller commands, unsafe paths and nonliteral argument values', function (array $override): void {
    $group = script_operation_group();
    $input = ['subtask' => $group->tasks()->firstOrFail()->id, 'script' => 'scripts/dependencies', 'args' => ['routine']];
    $this->postJson('/api/v1/task-groups/'.$group->id.'/script', $input)->assertUnprocessable();
    $this->postJson('/api/v1/task-groups/'.$group->id.'/script', array_replace($input, $override), ['Idempotency-Key' => 'bad'])
        ->assertUnprocessable();
    expect(Operation::query()->where('kind', 'tasks.script')->count())->toBe(0);
})->with([
    [['command' => 'echo unsafe']], [['script' => '../scripts/dependencies']], [['script' => '/bin/sh']],
    [['script' => 'scripts/../dependencies']], [['args' => ['mode' => 'routine']]], [['args' => [12]]], [['args' => ["a\0b"]]],
]);

it('refuses a tick group and a noncurrent child without dispatch', function (): void {
    $group = script_operation_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'tick']);
    $group->refresh();
    $input = ['subtask' => $group->tasks()->firstOrFail()->id, 'script' => 'scripts/dependencies', 'args' => []];
    $this->postJson('/api/v1/task-groups/'.$group->id.'/script', $input, ['Idempotency-Key' => 'tick'])->assertConflict()->assertJsonPath('error.code', 'tasks.not_orchestrated');
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'external']);
    $group->refresh();
    $this->postJson('/api/v1/task-groups/'.$group->id.'/script', [...$input, 'subtask' => 999999], ['Idempotency-Key' => 'foreign'])->assertNotFound();
    expect(app(TaskScriptRunner::class)->starts)->toBe(0);
});

it('tombstones an undispatched reservation rather than restarting it', function (): void {
    $group = script_operation_group();
    $operation = script_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $runner = app(TaskScriptRunner::class);
    $runner->processes = [];
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);

    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->fresh()->error_code)->toBe('tasks.script_not_started')
        ->and($runner->starts)->toBe(1)->and($runner->tombstones)->toHaveCount(1)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'script')->sole()->status)->toBe('released');
});

it('reuses a persisted terminal checkpoint after a worker loses its final result write', function (): void {
    $group = script_operation_group();
    $operation = script_operation_start($group);
    script_operation_finish($operation);
    $result = $operation->result;
    $state = $operation->state;
    unset($state['steps']['script']);
    $operation->update(['status' => 'waiting', 'state' => $state, 'current_step' => 'script', 'result' => null,
        'finished_at' => null, 'worker' => null, 'next_read_at' => now(), 'lock_key' => 'task_group:'.$group->id]);
    $operation->steps()->where('name', 'script')->update(['status' => 'waiting', 'finished_at' => null]);
    $runner = app(TaskScriptRunner::class);
    $runner->uncertain = true;
    $recovers = $runner->recovers;

    app(OperationRunner::class)->read($operation->id);

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)->and($operation->fresh()->result)->toBe($result)
        ->and($runner->recovers)->toBe($recovers)->and($runner->starts)->toBe(1);
});

it('records the queued SDK fixture and terminal result for the script operation', function (): void {
    $group = script_operation_group();
    $input = ['subtask' => $group->tasks()->firstOrFail()->id, 'script' => 'scripts/dependencies', 'args' => ['routine']];
    $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/script', $input, ['Idempotency-Key' => 'script.fixture'])->assertAccepted();
    record_fixture($response, 'tasks/tasks-script/queued', ScriptTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/script');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    script_operation_finish($operation);
    record_fixture($this->getJson('/api/v1/operations/'.$operation->id)->assertOk(), 'tasks/tasks-script/passed', ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
});
