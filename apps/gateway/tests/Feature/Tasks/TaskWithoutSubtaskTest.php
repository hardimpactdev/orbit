<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskTurnProjection;
use App\Domain\Tasks\TaskType;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('tasks', true);
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    // The SSH reads of a copy's create: the ID map of a roomy host with no container, and a clean default's workspace.
    app()->instance(SshExecutor::class, new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $answer = str_contains((string) $command->input, 'ID map facts')
                ? ['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => []]
                : ['state' => 'clean', 'baseline' => 'none', 'head' => 'main', 'modified' => ['count' => 0, 'names' => []], 'untracked' => ['count' => 0, 'names' => []],
                    'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];

            return new CommandResult(0, json_encode($answer, JSON_THROW_ON_ERROR), '', 5, false);
        }
    });
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);
});

/** A Project whose container default is active and running with a seed, so a task of it starts as a copy. */
function soloProject(): Project
{
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    Http::fake(['api.github.com/repos/nckrtl/solo' => Http::response(['full_name' => 'nckrtl/solo', 'private' => false])]);
    $project = Project::query()->create(['name' => 'solo', 'slug' => 'solo', 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/solo.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => true, 'task_check' => 'bin/task-check']);
    $default = Instance::query()->findOrFail(containerCreate($project, $node->refresh())->target_id);
    Route::query()->where('project_id', $project->id)->update(['status' => 'active', 'sites_published' => true]);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running, 'inner_volume' => null]);

    return $project;
}

/** @return list<array<string, string>> */
function soloDeliverables(): array
{
    return [
        ['id' => 'note', 'type' => 'file', 'description' => 'The note exists.', 'path' => 'docs/note.md', 'change' => 'created'],
        ['id' => 'tests', 'type' => 'command', 'description' => 'The tests pass.', 'command' => 'vendor/bin/pest tests/Feature/NoteTest.php', 'directory' => 'apps/gateway'],
    ];
}

/** The task's copy as its finished create leaves it: active on its branch, running, the task running. */
function soloCopyFinished(Operation $create): Instance
{
    $create->forceFill(['status' => 'succeeded', 'finished_at' => now(), 'lock_key' => null])->save();
    $copy = Instance::query()->findOrFail($create->target_id);
    $copy->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => $copy->name, 'starting_commit' => str_repeat('c', 40)]);
    $copy->runtime?->update(['power_state' => InstanceRuntimePowerState::Running]);
    Task::topLevel()->findOrFail($copy->task_workspace_owner_id)->update(['status' => 'running']);

    return $copy->refresh();
}

/** The check's run ended with OUTCOME, as the check workflows write its receipt. */
function soloCheckEnded(Operation $check, string $outcome): void
{
    $input = $check->input;
    $receipt = ['outcome' => $outcome, 'exit_code' => $outcome === 'passed' ? 0 : 1, 'output' => 'checked', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40),
        'head' => str_repeat('d', 40), 'tree' => str_repeat('e', 40), 'changed_paths' => [], 'failed_step' => null, 'evidence' => null,
        'subtask_start_commit' => str_repeat('c', 40), 'check_id' => $input['check_id'], 'group_id' => $input['group_id'], 'subtask' => $input['subtask'],
        'phase' => $input['phase'], 'deliverables' => [], 'commit' => str_repeat('d', 40), 'ci' => null];
    $check->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'run-check']])->save();
    app(TaskCheckProjection::class)->apply($check->refresh(), ['run_id' => 'run-check', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['instance_receipt' => ['result_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]]]);
}

/** The implementer turn's run ended done in T3 thread THREAD. */
function soloTurnEnded(Operation $turn, string $thread): Operation
{
    $turn->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-turn']])->save();

    return app(TaskTurnProjection::class)->apply($turn->refresh(), ['status' => 'succeeded', 'run_id' => 'run-turn', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['turn' => ['state' => 'done', 'error' => null, 'ended' => true, 't3_thread_id' => $thread,
            'run_id' => 'run:1', 'launched' => true, 'provider_instance' => 'claudeMedium', 'model' => 'claude-opus-5-5', 'questions' => [], 'transcript' => []]]]);
}

it('creates a task with deliverables and no subtask, and takes it by its own id through baseline check, implementer turn and handoff check', function (): void {
    $project = soloProject();

    $created = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write the note and its test.',
        'status' => 'todo', 'deliverables' => soloDeliverables()])->assertCreated();
    $id = $created->json('data.id');
    $created->assertJsonPath('data.subtasks', [])->assertJsonPath('data.work.deliverables.1.id', 'tests')->assertJsonPath('data.work.status', 'todo');
    $work = Task::query()->where('parent_id', $id)->sole();
    expect($work->type)->toBe(TaskType::Work)
        ->and([$work->title, $work->brief])->toBe(['Add the note', 'Write the note and its test.']);

    $start = $this->withHeader('Idempotency-Key', 'solo-start')->postJson("/api/v1/tasks/{$id}/start")->assertStatus(202)->json('data.id');
    soloCopyFinished(Operation::query()->findOrFail($start));

    $baseline = $this->withHeader('Idempotency-Key', 'solo-baseline')->postJson("/api/v1/tasks/{$id}/check", ['phase' => 'baseline'])
        ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.check')->json('data.id');
    $baseline = Operation::query()->findOrFail($baseline);
    expect($baseline->input['subtask'])->toBe($work->id)->and($baseline->target_id)->toBe($id);
    soloCheckEnded($baseline, 'passed');

    $turn = $this->withHeader('Idempotency-Key', 'solo-turn')->postJson("/api/v1/tasks/{$id}/turn", ['role' => 'implementer', 'prompt' => 'Implement the brief.'])
        ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.copy_turn')->json('data.id');
    $turn = Operation::query()->findOrFail($turn);
    $prompt = $turn->input['turn']['prompt'] ?? $turn->input['prompt'];
    expect($turn->input['subtask'])->toBe($work->id)
        ->and($prompt)->toStartWith('You implement an Orbit task in its task copy.')
        ->toContain("<task_brief title=\"Add the note\">\nWrite the note and its test.\n</task_brief>\n\n<deliverables>\n- note (file: docs/note.md, created)")
        ->not->toContain('subtask');
    expect(soloTurnEnded($turn, 'mcp:1')->status)->toBe(OperationStatus::Succeeded);

    $failed = $this->withHeader('Idempotency-Key', 'solo-handoff-1')->postJson("/api/v1/tasks/{$id}/check", ['phase' => 'handoff'])->assertStatus(202)->json('data.id');
    $failed = Operation::query()->findOrFail($failed);
    expect($failed->input['deliverables'])->toBe([soloDeliverables()[0], [...soloDeliverables()[1], 'fails_on_base' => false]]);
    soloCheckEnded($failed, 'failed');

    // The failed handoff check lets the owner correct the deliverables by the task's id, with a reason.
    $this->patchJson("/api/v1/tasks/{$id}", ['deliverables' => [soloDeliverables()[1]], 'reason' => 'The note is not needed.'])->assertOk()
        ->assertJsonPath('data.work.deliverables', [soloDeliverables()[1]])->assertJsonPath('data.subtasks', []);
    expect($work->refresh()->copy_correction_check_id)->toBe($failed->input['check_id']);

    $handoff = $this->withHeader('Idempotency-Key', 'solo-handoff-2')->postJson("/api/v1/tasks/{$id}/check", ['phase' => 'handoff'])->assertStatus(202)->json('data.id');
    $handoff = Operation::query()->findOrFail($handoff);
    expect($handoff->input['deliverables'])->toBe([[...soloDeliverables()[1], 'fails_on_base' => false]]);
    soloCheckEnded($handoff, 'passed');

    expect(TaskCheck::query()->where('task_id', $work->id)->orderBy('id')->pluck('status')->all())->toBe([TaskCheckStatus::Passed, TaskCheckStatus::Failed, TaskCheckStatus::Passed])
        ->and(AgentThread::query()->where('task_group_id', $id)->sole()->task_id)->toBe($work->id);
    $this->getJson("/api/v1/tasks/{$id}")->assertOk()->assertJsonPath('data.subtasks', [])
        ->assertJsonPath('data.work.id', $work->id)->assertJsonPath('data.work.check.kind', 'handoff')->assertJsonPath('data.work.check.status', 'passed');
});

it('corrects the deliverables of a task with no subtask in backlog by the task\'s own id', function (): void {
    $project = soloProject();
    $id = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write it.', 'deliverables' => soloDeliverables()])
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/tasks/{$id}", ['deliverables' => [soloDeliverables()[0]]])->assertOk()
        ->assertJsonPath('data.work.deliverables', [soloDeliverables()[0]])->assertJsonPath('data.subtasks', []);
    // A field of the task itself is not a field of its work: the brief changes the task, and its work repeats it.
    $this->patchJson("/api/v1/tasks/{$id}", ['deliverables' => soloDeliverables(), 'brief' => 'Other.'])->assertUnprocessable();
    $this->patchJson("/api/v1/tasks/{$id}", ['title' => 'Add the notes', 'brief' => 'Write them.', 'status' => 'todo'])->assertOk()
        ->assertJsonPath('data.status', 'todo')->assertJsonPath('data.work.title', 'Add the notes')->assertJsonPath('data.work.brief', 'Write them.');
});

it('refuses a task that names both deliverables and subtasks, a todo task without deliverables, and a subtask added to a task with no subtask', function (): void {
    $project = soloProject();
    $base = ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write it.'];

    $this->postJson('/api/v1/tasks', [...$base, 'deliverables' => soloDeliverables(), 'subtasks' => [['title' => 'A', 'brief' => 'B']]])
        ->assertUnprocessable()->assertJsonPath('error.details.subtasks.0', 'The subtasks field prohibits deliverables from being present.');
    $this->postJson('/api/v1/tasks', [...$base, 'status' => 'todo', 'deliverables' => []])
        ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing');
    $id = $this->postJson('/api/v1/tasks', [...$base, 'deliverables' => soloDeliverables()])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/tasks', ['parent_id' => $id, 'title' => 'More', 'brief' => 'More work.'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.own_work');

    expect(Task::query()->where('parent_id', $id)->count())->toBe(1);
});
