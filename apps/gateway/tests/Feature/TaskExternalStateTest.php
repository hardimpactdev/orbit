<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;

/** @return array{Task, Task} */
function external_state_group(string $status = 'running', string $subtaskStatus = 'running', TaskOrchestration $orchestration = TaskOrchestration::External): array
{
    $gateway = test()->markAsGateway(Node::query()->create([
        'name' => 'external-state-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90', 'wireguard_ip' => '10.44.0.90',
    ]));
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'External state', 'slug' => 'external-state', 'repository_url' => 'https://github.com/acme/demo.git']);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'External state', 'brief' => 'Orchestrated work.',
        'status' => TaskGroupStatus::from($status), 'orchestration' => $orchestration,
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'Do it.', 'status' => TaskStatus::from($subtaskStatus),
        'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']],
    ]);

    return [$group->refresh(), $task->refresh()];
}

it('updates each allowed external group status without claiming work', function (string $from, string $to): void {
    [$group, $task] = external_state_group($from);
    $this->mock(InstanceProvisioning::class)->shouldNotReceive('provision');
    $before = $task->getRawOriginal();

    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => $to])->assertOk()->assertJsonPath('data.status', $to);

    expect($group->fresh()->status->value)->toBe($to);
    expect($group->fresh()->reserved_at)->toBeNull();
    expect($task->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['backlog', 'todo'], ['todo', 'backlog'],
    ['running', 'reviewing'], ['running', 'settling'], ['running', 'waiting_for_review'],
    ['reviewing', 'running'], ['reviewing', 'settling'], ['reviewing', 'waiting_for_review'],
    ['settling', 'running'], ['settling', 'reviewing'], ['settling', 'waiting_for_review'],
    ['waiting_for_review', 'running'], ['waiting_for_review', 'reviewing'], ['waiting_for_review', 'settling'],
]);

it('refuses external group moves across lifecycle boundaries with 422', function (string $from, string $to): void {
    [$group] = external_state_group($from);
    $before = $group->getRawOriginal();

    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => $to])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.status_move_refused');

    expect($group->fresh()->getRawOriginal())->toBe($before);
})->with([
    'only provision reserves' => ['todo', 'reserved'], 'cannot skip provision' => ['todo', 'running'],
    'cannot leave reservation' => ['reserved', 'running'], 'cannot restore preparation' => ['running', 'backlog'],
    'cannot restore ready' => ['running', 'todo'], 'cannot complete' => ['settling', 'completed'],
    'cannot fail' => ['running', 'failed'], 'cannot cancel' => ['running', 'cancelled'],
    'cannot reopen completed' => ['completed', 'running'], 'cannot reopen failed' => ['failed', 'todo'],
    'cannot reopen cancelled' => ['cancelled', 'backlog'],
]);

it('changes nothing when the current external group status repeats', function (string $status): void {
    $this->freezeTime();
    [$group] = external_state_group($status);
    $before = $group->getRawOriginal();
    $this->travel(1)->minutes();

    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);

    expect($group->fresh()->getRawOriginal())->toBe($before);
})->with(['backlog', 'todo', 'reserved', 'running', 'reviewing', 'settling', 'waiting_for_review', 'completed', 'failed', 'cancelled']);

it('keeps external todo deliverable requirements', function (bool $hasSubtask): void {
    [$group, $task] = external_state_group('backlog', 'todo');
    if ($hasSubtask) {
        $task->update(['deliverables' => []]);
    } else {
        $task->delete();
    }

    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => 'todo'])->assertUnprocessable()
        ->assertJsonPath('error.code', $hasSubtask ? 'tasks.subtask_deliverables_missing' : 'tasks.no_subtasks');

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Backlog);
})->with([true, false]);

it('updates each allowed external subtask status without changing its group', function (string $from, string $to): void {
    [$group, $task] = external_state_group('running', $from);
    $before = $group->getRawOriginal();

    $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['status' => $to])->assertOk()->assertJsonPath('data.status', $to);

    expect($task->fresh()->status->value)->toBe($to);
    expect($group->fresh()->getRawOriginal())->toBe($before);
    expect(AgentThread::query()->count())->toBe(0);
    expect(TaskCheck::query()->count())->toBe(0);
})->with([
    ['todo', 'running'], ['running', 'reviewing'], ['reviewing', 'running'],
    ['running', 'completed'], ['running', 'failed'], ['reviewing', 'completed'], ['reviewing', 'failed'],
]);

it('refuses external subtask moves outside the contract with 422', function (string $from, string $to): void {
    [$group, $task] = external_state_group('running', $from);
    $before = $task->getRawOriginal();

    $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['status' => $to])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.status_move_refused');

    expect($task->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['todo', 'reviewing'], ['todo', 'completed'], ['todo', 'failed'], ['todo', 'reserved'], ['todo', 'cancelled'],
    ['running', 'todo'], ['running', 'reserved'], ['running', 'cancelled'], ['reviewing', 'todo'], ['reviewing', 'cancelled'],
    ['completed', 'running'], ['completed', 'failed'], ['failed', 'running'], ['failed', 'completed'], ['cancelled', 'running'], ['reserved', 'running'],
]);

it('refuses a subtask status move in an ended external group with 422', function (string $status): void {
    [$group, $task] = external_state_group($status);

    $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['status' => 'completed'])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.status_move_refused');

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
})->with(['completed', 'failed', 'cancelled']);

it('changes nothing when the current external subtask status repeats', function (string $status): void {
    $this->freezeTime();
    [$group, $task] = external_state_group('running', $status);
    $before = $task->getRawOriginal();
    $this->travel(1)->minutes();

    $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);

    expect($task->fresh()->getRawOriginal())->toBe($before);
})->with(['todo', 'reserved', 'running', 'reviewing', 'completed', 'failed', 'cancelled']);

it('rolls back a status move combined with a forbidden metadata update', function (): void {
    [$group, $task] = external_state_group();

    $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['status' => 'completed', 'title' => 'Changed'])->assertConflict()->assertJsonPath('error.code', 'tasks.not_in_backlog');

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect($task->fresh()->title)->toBe('One');
});

it('keeps tick group status input unchanged and refuses subtask status input', function (): void {
    [$group, $task] = external_state_group('running', 'running', TaskOrchestration::Tick);

    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => 'reviewing'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => 'todo'])->assertConflict()->assertJsonPath('error.code', 'tasks.already_claimed');
    $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['status' => 'reviewing'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

it('clears external assistance on resolution without sending or queuing work', function (string $status): void {
    [$group, $task] = external_state_group('running', $status);
    $driver = new FakeAgentDriver('fake');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 'fake', 'runtime_key' => 'external', 'external_id' => 'external-session', 'role' => $status === 'reviewing' ? 'reviewer' : 'implementer',
    ]);
    $task->update(['implementer_agent_thread_id' => $thread->id]);
    $url = "/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments";
    $this->postJson($url, ['type' => 'assistance_requested', 'body' => 'Which direction?', 'author' => 'orchestrator'])->assertCreated();
    expect($task->fresh()->assistance_requested)->toBeTrue();
    expect($group->fresh()->assistance_requested)->toBeTrue();

    $resolution = $this->postJson($url, ['type' => 'resolution', 'body' => 'Continue.', 'author' => 'operator'])->assertCreated()->json('data.id');

    foreach ([$task->fresh(), $group->fresh()] as $record) {
        expect($record->assistance_requested)->toBeFalse();
        expect($record->assistance_kind)->toBeNull();
        expect($record->assistance_question)->toBeNull();
        expect($record->assistance_reason)->toBeNull();
    }
    expect($task->fresh()->status->value)->toBe($status);
    expect($task->fresh()->pending_resolution_comment_id)->toBeNull();
    expect($driver->calls)->toBe([]);
    expect(Activity::query()->where('description', 'assistance cleared')->where('properties->comment_id', $resolution)->count())->toBe(2);
})->with(['running', 'reviewing']);

it('preserves an independent group request when an external subtask is resolved', function (): void {
    [$group, $task] = external_state_group();
    TaskAssistance::apply($task, AssistanceKind::Direction, 'One?', 'One?');
    TaskAssistance::apply($group, AssistanceKind::Direction, 'Other?', 'Other?');

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments", ['type' => 'resolution', 'body' => 'Answer.', 'author' => 'operator'])->assertCreated();

    expect($task->fresh()->assistance_requested)->toBeFalse();
    expect($group->fresh()->assistance_requested)->toBeTrue();
    expect($group->fresh()->assistance_question)->toBe('Other?');
});

it('cancels external active work without advancing or settling', function (string $status, bool $hasNext): void {
    [$group, $task] = external_state_group('running', $status);
    $next = $hasNext ? Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Next', 'brief' => 'Wait.']) : null;
    external_cancel_workspace($group);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $holder = external_cancel_turn($group, $task, $driver, $status === 'reviewing' ? 'reviewer' : 'implementer');
    $before = $group->getRawOriginal();
    $level = DB::transactionLevel();

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($driver->calls)->toBe([['operation' => 'interrupt', 'thread' => 'external-turn']]);
    expect($driver->interruptTransactionLevels)->toBe([$level]);
    expect($checks->cancels)->toBe(0);
    expect($checks->starts)->toBe(0);
    expect($holder->fresh()->status)->toBe('released');
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect($task->fresh()->settled_at)->not->toBeNull();
    expect($group->fresh()->getRawOriginal())->toBe($before);
    expect($next?->fresh()->status)->toBe($hasNext ? TaskStatus::Todo : null);
})->with([['running', true], ['running', false], ['reviewing', true], ['reviewing', false]]);

it('cancels an external subtask with no lease holder without remote stops', function (string $status): void {
    [$group, $task] = external_state_group('running', $status);
    external_cancel_workspace($group);
    $checks = new FakeTaskCheckRunner;
    $driver = new FakeAgentDriver('fake');
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
    expect($checks->cancels)->toBe(0);
    expect($driver->calls)->toBe([]);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->exists())->toBeFalse();
})->with(['todo', 'running', 'reviewing']);

it('keeps external work active when its leased turn cannot be confirmed stopped', function (string $outcome): void {
    [$group, $task] = external_state_group();
    external_cancel_workspace($group);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $holder = external_cancel_turn($group, $task, $driver);
    $driver->failNextInterrupt = $outcome === 'interrupt';
    $key = $driver->observation->turnId;
    $driver->duringInterrupt = function () use ($driver, $outcome, $key): void {
        $driver->observation = match ($outcome) {
            'working' => new AgentObservation(AgentThreadState::Working, turnId: $key),
            'idle' => new AgentObservation(AgentThreadState::Idle, turnId: $key),
            'old' => new AgentObservation(AgentThreadState::Done, turnId: 'before'),
            default => new AgentObservation(null),
        };
    };

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertStatus(502)->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
    expect($holder->fresh()->status)->toBe('held');
})->with(['interrupt', 'working', 'idle', 'unknown', 'old']);

it('updates external board state through the generated MCP tools', function (string $name, string $to): void {
    [$group, $task] = external_state_group();
    $arguments = ['group' => $group->id, 'status' => $to];
    if ($name === 'tasks-subtask-update') {
        $arguments['task'] = $task->id;
    }

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk();
    $payload = json_decode($response->json('result.content.0.text'), true);

    expect($response->json('result.isError'))->toBeFalse();
    expect($payload['data']['status'])->toBe($to);
    expect(($name === 'tasks-update' ? $group : $task)->fresh()->status->value)->toBe($to);
})->with([['tasks-update', 'waiting_for_review'], ['tasks-subtask-update', 'completed']]);

it('keeps external group assistance aligned when subtasks resolve or cancel in either order', function (bool $reverse, bool $cancel): void {
    [$group, $first] = external_state_group();
    $second = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Two', 'brief' => 'Next.']);
    foreach ([[$first, 'First?'], [$second, 'Second?']] as [$task, $question]) {
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments", ['type' => 'assistance_requested', 'body' => $question, 'author' => 'orchestrator'])->assertCreated();
    }
    [$answered, $pending] = $reverse ? [$second, $first] : [$first, $second];
    $answer = function (Task $task) use ($group, $cancel): void {
        $url = "/api/v1/task-groups/{$group->id}/tasks/{$task->id}";
        if ($cancel) {
            $this->postJson($url.'/cancel')->assertOk();
        } else {
            $this->postJson($url.'/comments', ['type' => 'resolution', 'body' => 'Answer.', 'author' => 'operator'])->assertCreated();
        }
    };

    $answer($answered);

    expect($answered->fresh()->assistance_requested)->toBeFalse();
    expect($pending->fresh()->assistance_requested)->toBeTrue();
    expect($group->fresh()->assistance_requested)->toBeTrue();
    expect($group->fresh()->assistance_question)->toBe($reverse ? 'First?' : 'Second?');

    $answer($pending);

    expect($first->fresh()->assistance_requested)->toBeFalse();
    expect($second->fresh()->assistance_requested)->toBeFalse();
    expect($group->fresh()->assistance_requested)->toBeFalse();
    expect($group->fresh()->assistance_question)->toBeNull();
})->with([[true, false], [false, false], [true, true], [false, true]]);

it('reconciles external assistance when an asking subtask completes before resolution', function (bool $hasSibling, bool $independent): void {
    [$group, $task] = external_state_group();
    $url = "/api/v1/task-groups/{$group->id}/tasks/{$task->id}";
    $this->postJson($url.'/comments', ['type' => 'assistance_requested', 'body' => 'First?', 'author' => 'orchestrator'])->assertCreated();
    if ($hasSibling) {
        $sibling = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Two', 'brief' => 'Next.']);
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$sibling->id}/comments", ['type' => 'assistance_requested', 'body' => 'Second?', 'author' => 'orchestrator'])->assertCreated();
        // Make the completing subtask's request the one the group currently displays.
        $this->postJson($url.'/comments', ['type' => 'assistance_requested', 'body' => 'First?', 'author' => 'orchestrator'])->assertCreated();
    }
    if ($independent) {
        TaskAssistance::apply($group, AssistanceKind::Direction, 'Independent?', 'Independent?', replaceDirection: true);
    }

    $this->patchJson($url, ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');

    expect($task->fresh()->assistance_requested)->toBeFalse();
    expect($group->fresh()->assistance_requested)->toBe($hasSibling || $independent);
    expect($group->fresh()->assistance_question)->toBe($independent ? 'Independent?' : ($hasSibling ? 'Second?' : null));
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);

    $this->postJson($url.'/comments', ['type' => 'resolution', 'body' => 'Answer.', 'author' => 'operator'])->assertCreated();

    expect($group->fresh()->assistance_requested)->toBe($hasSibling || $independent);
    expect($group->fresh()->assistance_question)->toBe($independent ? 'Independent?' : ($hasSibling ? 'Second?' : null));
})->with([[false, false], [true, false], [false, true]]);

function external_cancel_workspace(Task $group): Instance
{
    $instance = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => Node::query()->firstOrFail()->id, 'name' => 'external-cancel', 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/orbit/external-cancel', 'starting_commit' => str_repeat('a', 40), 'status' => 'source_resolved']);
    $group->taskable()->associate($instance);
    $group->save();

    return $instance;
}

it('cancels the leased external check regardless of its subtask board status', function (string $status, bool $interrupted): void {
    [$group, $task] = external_state_group('running', $status);
    external_cancel_workspace($group);
    $checks = new FakeTaskCheckRunner([$interrupted ? TaskCheckReading::interrupted('Stopped.') : FakeTaskCheckRunner::passed()]);
    app()->instance(TaskCheckRunner::class, $checks);
    $leases = app(TaskWorkspaceLease::class);
    $leases->run($group, 'check', 'check:'.$task->id, fn () => $leases->startCheck($group, $task, 'composer test'), taskId: $task->id);
    $holder = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->firstOrFail();
    $check = TaskCheck::query()->findOrFail($holder->check_id);
    $level = DB::transactionLevel();

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertOk();

    expect($checks->cancels)->toBe(1);
    expect($checks->cancelTransactionLevels)->toBe([$level]);
    expect($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled);
    expect($holder->fresh()->status)->toBe('released');
    expect($holder->fresh()->getAttribute('causality_uncertain'))->toBe($interrupted);
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
})->with([['todo', false], ['running', false], ['reviewing', false], ['todo', true], ['running', true], ['reviewing', true]]);

it('interrupts the leased implementer after the external board moves to reviewing', function (): void {
    [$group, $task] = external_state_group();
    external_cancel_workspace($group);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $holder = external_cancel_turn($group, $task, $driver, externalId: 'leased-implementer');
    $url = "/api/v1/task-groups/{$group->id}/tasks/{$task->id}";
    $this->patchJson($url, ['status' => 'reviewing'])->assertOk();

    $this->postJson($url.'/cancel')->assertOk();

    expect($driver->calls)->toBe([['operation' => 'interrupt', 'thread' => 'leased-implementer']]);
    expect($holder->fresh()->status)->toBe('released');
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

it('keeps external work uncancelled when the leased check has no confirmed stop', function (string $outcome): void {
    [$group, $task] = external_state_group('running', 'todo');
    external_cancel_workspace($group);
    $checks = new FakeTaskCheckRunner([$outcome === 'running' ? TaskCheckReading::running() : TaskCheckReading::lost('No result.')]);
    $checks->failNextCancel = $outcome === 'unreachable';
    app()->instance(TaskCheckRunner::class, $checks);
    $leases = app(TaskWorkspaceLease::class);
    $leases->run($group, 'check', 'check:'.$task->id, fn () => $leases->startCheck($group, $task, 'composer test'), taskId: $task->id);
    $holder = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->firstOrFail();

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertStatus(502)->assertJsonPath('error.code', 'tasks.check_unreachable');

    expect($checks->cancels)->toBe(1);
    expect($task->fresh()->status)->toBe(TaskStatus::Todo);
    expect(TaskCheck::query()->findOrFail($holder->check_id)->status)->toBe(TaskCheckStatus::Running);
    expect($holder->fresh()->status)->toBe('held');
})->with(['running', 'lost', 'unreachable']);

function external_cancel_turn(Task $group, Task $task, FakeAgentDriver $driver, string $role = 'implementer', string $externalId = 'external-turn'): TaskWorkspaceLeaseRecord
{
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'before');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 'fake', 'runtime_key' => 'external', 'external_id' => $externalId, 'role' => $role]);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->turn($group, TaskThreadRole::from($role), 'thread:'.$thread->id, $thread->id);
    $key = $leases->dispatch($thread, $driver->observation);
    $driver->observation = new AgentObservation(AgentThreadState::Working, turnId: $key);
    $driver->duringInterrupt = function () use ($driver, $key): void {
        $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: $key);
    };

    return $holder;
}

it('refuses external cancellation if a lease is admitted before the cancellation commit', function (): void {
    [$group, $task] = external_state_group();
    external_cancel_workspace($group);
    $leases = app(TaskWorkspaceLease::class);

    expect(fn () => app(TaskScheduler::class)->cancelRunningSubtask($group, $task, function () use ($leases, $group, $task): void {
        $leases->acquire($group, 'check', 'new-owner', taskId: $task->id);
    }))->toThrow(ResourceOperationException::class, 'A workspace actor still holds the subtask lease.');

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->owner)->toBe('new-owner');
});
