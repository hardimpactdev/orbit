<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
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
    $node = Node::query()->firstOrFail();
    $instance = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $node->id, 'name' => 'task-workspace', 'checkout_path' => '/srv/orbit/apps/external-state/task-workspace', 'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'task_workspace_owner_id' => $group->id, 'status' => 'source_resolved']);
    $group->taskable()->associate($instance);
    $group->save();
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 'fake', 'runtime_key' => 'external', 'external_id' => 'external-turn', 'role' => $status === 'reviewing' ? 'reviewer' : 'implementer',
    ]);
    $task->update(['implementer_agent_thread_id' => $status === 'running' ? $thread->id : null]);
    $check = TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
        'pid' => 4100, 'process_started' => 'Wed Sep 23 12:00:00 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $checks = new FakeTaskCheckRunner;
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $before = $group->getRawOriginal();
    $level = DB::transactionLevel();

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($driver->calls)->toBe([['operation' => 'interrupt', 'thread' => 'external-turn']]);
    expect($driver->interruptTransactionLevels)->toBe([$level]);
    expect($checks->cancelTransactionLevels)->toBe([$level]);
    expect($checks->cancels)->toBe(1);
    expect($checks->starts)->toBe(0);
    expect($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled);
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect($task->fresh()->settled_at)->not->toBeNull();
    expect($group->fresh()->getRawOriginal())->toBe($before);
    expect($next?->fresh()->status)->toBe($hasNext ? TaskStatus::Todo : null);
})->with([['running', true], ['running', false], ['reviewing', true], ['reviewing', false]]);

it('cancels an external todo subtask without settling its group', function (): void {
    [$group, $task] = external_state_group('todo', 'todo');

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Todo);
});

it('keeps external work active when its turn cannot be interrupted', function (): void {
    [$group, $task] = external_state_group();
    AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 'fake', 'runtime_key' => 'external', 'external_id' => 'external-turn', 'role' => 'implementer']);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $driver->failNextInterrupt = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")->assertStatus(502)->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

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
