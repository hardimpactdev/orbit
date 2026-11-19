<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectMaintenanceProjection;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskStatus;
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

it('refuses both shapes in one create before it writes anything or reads the repository, and reads the shape from the body alone', function (array $shape): void {
    $project = soloProject();
    // The repository is unavailable: a create that reached the path check would answer tasks.deliverable_base_unavailable.
    app()->instance(DeliverablePathRepository::class, new class implements DeliverablePathRepository
    {
        public function defaultBranchCommit(Project $project): string
        {
            throw new RuntimeException('The repository was read.');
        }

        public function files(Project $project, string $commit): array
        {
            throw new RuntimeException('The repository was read.');
        }
    });

    $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write it.', ...$shape])
        ->assertUnprocessable()->assertJsonPath('error.details.subtasks.0', 'A task takes either subtasks or its own deliverables, not both.');
    // A query parameter is no body field: the task takes no work from it.
    $id = $this->postJson('/api/v1/tasks?deliverables=1', ['project_id' => $project->id, 'title' => 'Plain', 'brief' => 'Plan it.'])->assertCreated()->json('data.id');

    expect(Task::topLevel()->pluck('id')->all())->toBe([$id])
        ->and(Task::query()->count())->toBe(0);
})->with([
    'a modified file at the top level' => [['deliverables' => [['id' => 'doc', 'type' => 'file', 'description' => 'Changed.', 'path' => 'README.md', 'change' => 'modified']], 'subtasks' => []]],
    'a modified file in a subtask' => [['deliverables' => [], 'subtasks' => [['title' => 'A', 'brief' => 'B', 'deliverables' => [['id' => 'doc', 'type' => 'file', 'description' => 'Changed.', 'path' => 'README.md', 'change' => 'modified']]]]]],
    'two subtasks and no deliverables' => [['subtasks' => [['title' => 'A', 'brief' => 'B'], ['title' => 'C', 'brief' => 'D']], 'deliverables' => []]],
    'deliverables and a subtask' => [['deliverables' => soloDeliverables(), 'subtasks' => [['title' => 'A', 'brief' => 'B']]]],
    'two empty lists' => [['deliverables' => [], 'subtasks' => []]],
]);

it('writes the task and its work together or not at all', function (): void {
    $project = soloProject();
    Task::creating(static function (Task $task): void {
        if ($task->type === TaskType::Work) {
            throw new RuntimeException('The work row was not written.');
        }
    });

    $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write it.', 'status' => 'todo', 'deliverables' => soloDeliverables()])
        ->assertServerError();

    expect(Task::topLevel()->count())->toBe(0);
});

it('addresses the work only through the task: its row id is no task to read, change, delete or cancel', function (): void {
    $project = soloProject();
    $id = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write it.', 'deliverables' => soloDeliverables()])
        ->assertCreated()->json('data.id');
    $work = Task::query()->where('parent_id', $id)->sole();

    $this->getJson("/api/v1/tasks/{$work->id}")->assertNotFound();
    $this->patchJson("/api/v1/tasks/{$work->id}", ['brief' => 'Other.'])->assertNotFound();
    $this->deleteJson("/api/v1/tasks/{$work->id}")->assertNotFound();
    $this->withHeader('Idempotency-Key', 'solo-cancel')->postJson("/api/v1/tasks/{$work->id}/cancel")->assertNotFound();
    $this->postJson("/api/v1/tasks/{$work->id}/comments", ['body' => 'Hi.'])->assertNotFound();

    expect($work->refresh()->only(['brief', 'status']))->toBe(['brief' => 'Write it.', 'status' => TaskStatus::Todo])
        ->and($work->deliverables)->toBe(soloDeliverables());
});

it('lets a backlog task without subtasks take its own deliverables, and an empty work give way to subtasks', function (): void {
    $project = soloProject();
    $draft = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Plan', 'brief' => 'Plan it.'])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/tasks/{$draft}", ['deliverables' => soloDeliverables()])->assertOk()
        ->assertJsonPath('data.work.deliverables.0.id', 'note')->assertJsonPath('data.work.title', 'Plan')->assertJsonPath('data.subtasks', []);

    // A task whose last subtask was deleted in backlog takes its own deliverables too.
    $split = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Split', 'brief' => 'Split it.', 'subtasks' => [['title' => 'A', 'brief' => 'B']]])
        ->assertCreated()->json('data.subtasks.0.id');
    $parent = Task::query()->findOrFail($split)->parent_id;
    $this->deleteJson("/api/v1/tasks/{$split}")->assertOk();
    $this->patchJson("/api/v1/tasks/{$parent}", ['deliverables' => soloDeliverables()])->assertOk()->assertJsonPath('data.work.deliverables.1.id', 'tests');

    // An empty work gives way to the first subtask; a work with deliverables is not dropped.
    $empty = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Empty', 'brief' => 'Empty.', 'deliverables' => []])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/tasks', ['parent_id' => $empty, 'title' => 'First', 'brief' => 'First part.'])->assertCreated();
    $this->postJson('/api/v1/tasks', ['parent_id' => $draft, 'title' => 'More', 'brief' => 'More work.'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.own_work');
    $this->patchJson("/api/v1/tasks/{$draft}", ['deliverables' => []])->assertOk();
    $this->postJson('/api/v1/tasks', ['parent_id' => $draft, 'title' => 'More', 'brief' => 'More work.'])->assertCreated();

    expect(Task::query()->where('parent_id', $empty)->pluck('title')->all())->toBe(['First'])
        ->and(Task::query()->where('parent_id', $draft)->pluck('title')->all())->toBe(['More'])
        // A task with subtasks takes no deliverables of its own.
        ->and($this->patchJson("/api/v1/tasks/{$draft}", ['deliverables' => soloDeliverables()])->status())->toBe(422);
});

it('refuses a todo task without deliverables, and a subtask added to a started task with no subtask', function (): void {
    $project = soloProject();
    $base = ['project_id' => $project->id, 'title' => 'Add the note', 'brief' => 'Write it.'];

    $this->postJson('/api/v1/tasks', [...$base, 'status' => 'todo', 'deliverables' => []])
        ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing');
    $id = $this->postJson('/api/v1/tasks', [...$base, 'status' => 'todo', 'deliverables' => soloDeliverables()])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/tasks', ['parent_id' => $id, 'title' => 'More', 'brief' => 'More work.'])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.own_work')
        ->assertJsonPath('error.message', 'A started task with no subtask takes no subtask. Create a new task for the new outcome.');

    expect(Task::query()->where('parent_id', $id)->count())->toBe(1);
});

it('leaves a draft as it was when an edit of its work is refused or fails', function (): void {
    $project = soloProject();
    $draft = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Plan', 'brief' => 'Plan it.'])->assertCreated()->json('data.id');

    // The first deliverables with a reason that no correction takes: refused, and the draft opens no work.
    $this->patchJson("/api/v1/tasks/{$draft}", ['deliverables' => soloDeliverables(), 'reason' => 'No check failed.'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'tasks.reason_unexpected');
    $this->getJson("/api/v1/tasks/{$draft}")->assertOk()->assertJsonPath('data.work', null);
    expect(Task::query()->where('parent_id', $draft)->count())->toBe(0);

    // The first subtask of a draft with empty work fails to insert: the empty work stays.
    $empty = $this->postJson('/api/v1/tasks', ['project_id' => $project->id, 'title' => 'Empty', 'brief' => 'Empty.', 'deliverables' => []])->assertCreated()->json('data.id');
    $work = Task::query()->where('parent_id', $empty)->sole();
    Task::creating(static function (Task $task): void {
        throw new RuntimeException('The subtask was not written.');
    });
    $this->postJson('/api/v1/tasks', ['parent_id' => $empty, 'title' => 'First', 'brief' => 'First part.'])->assertServerError();

    expect(Task::query()->where('parent_id', $empty)->pluck('id')->all())->toBe([$work->id]);
});

/** The maintenance Project of a routine run: a solo Project that opted in. */
function maintenanceSolo(): Project
{
    $project = soloProject();
    $project->update(['maintenance' => true, 'source_access' => 'github_app']);

    return $project;
}

/** @return list<array<string, string>> */
function maintenanceDeliverables(): array
{
    return [['id' => 'project-check', 'type' => 'command', 'description' => 'Pass the configured project check in the task checkout.', 'command' => 'bin/task-check', 'directory' => '.'],
        ['id' => 'maintenance-review', 'type' => 'review', 'description' => 'The update keeps the constraints.']];
}

/**
 * Projects a routine run of PROJECT whose result asks for the group of INPUT, the shape fields of its create body.
 *
 * @param  array<string, mixed>  $input
 */
function maintenanceRoutine(Project $project, array $input): void
{
    $admitted = ['projectId' => $project->id, 'repository' => $project->repository_url, 'default_branch' => 'main', 'check' => 'bin/task-check'];
    $operation = Operation::query()->create(['kind' => 'project.routine', 'target_type' => 'project', 'target_id' => $project->id, 'node_id' => null,
        'input' => ['project' => $admitted], 'state' => ['swamp_run_id' => 'run-1'], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);
    app(ProjectMaintenanceProjection::class)->apply($operation, ['run_id' => 'run-1', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'status' => 'succeeded', 'result' => ['maintenance' => ['project_id' => $project->id, 'requests' => [[
            'action' => 'create_group', 'project_id' => $project->id, 'key' => $project->id.'-routine-2026-11-16',
            'input' => ['project_id' => $project->id, 'title' => 'Update dependencies within current constraints', 'brief' => 'Update dependencies. Swamp schedule: routine',
                'status' => 'backlog', 'orchestration' => 'external', ...$input],
            'maintenance' => ['mode' => 'routine', 'project' => $admitted, 'targets' => []],
        ]]]]]);
}

// A maintenance task has no subtask: it holds its own deliverables, and project maintenance still moves it to todo and starts it.
it('admits the maintenance task of a routine run as a task with no subtask, and starts it as a copy', function (): void {
    $project = maintenanceSolo();

    maintenanceRoutine($project, ['deliverables' => maintenanceDeliverables()]);

    $group = Task::topLevel()->where('project_id', $project->id)->sole();
    $rows = Task::query()->where('parent_id', $group->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->type)->toBe(TaskType::Work)
        ->and($rows->first()->deliverables)->toBe(maintenanceDeliverables())
        ->and($group->assistance_reason)->toBeNull()
        // The provision started: the task holds its copy's create and is reserved for it.
        ->and($group->status->value)->toBe('reserved')
        ->and(Operation::query()->find($group->maintenance['operation_id'] ?? null)?->kind)->toBe('instance.create')
        ->and($group->taskable_id)->not->toBeNull();
});

// Maintenance moves its task out of backlog by the rule every task meets: a result in the old shape, or a reused draft of one subtask
// or of no work, stays in backlog, asks for a person and starts nothing.
it('keeps a maintenance task of one subtask or of no work in backlog, created or reused, and starts nothing', function (Closure $draft, array $input, string $code): void {
    $project = maintenanceSolo();
    $draft($project);
    $creates = Operation::query()->where('kind', 'instance.create')->count();

    maintenanceRoutine($project, $input);

    $group = Task::topLevel()->where('project_id', $project->id)->sole();
    expect($group->status->value)->toBe('backlog')
        ->and($group->assistance_requested)->toBeTrue()
        ->and($group->assistance_reason)->toStartWith('Maintenance task refused in backlog: '.$code.'. ')
        ->and($group->assistance_reason)->toContain('at least two')
        // A refused task is not admitted.
        ->and(Operation::query()->where('kind', 'project.routine')->sole()->result['admitted_group_ids'])->toBe([])
        ->and($group->taskable_id)->toBeNull()
        ->and(Operation::query()->where('kind', 'instance.create')->count())->toBe($creates);
})->with([
    'an in-flight result with one subtask' => [static function (): void {}, ['tasks' => [['title' => 'Update', 'brief' => 'Update.', 'deliverables' => maintenanceDeliverables()]]], 'tasks.one_subtask'],
    'a reused draft with one subtask' => [static function (Project $project): void {
        $draft = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Update dependencies within current constraints', 'brief' => 'Update dependencies. Swamp schedule: routine',
            'status' => 'backlog', 'orchestration' => 'external', 'implementer_model' => 'claude-opus-5-5', 'reviewer_model' => 'claude-opus-5-5']);
        Task::query()->create(['parent_id' => $draft->id, 'position' => 1, 'title' => 'Update', 'brief' => 'Update.', 'deliverables' => maintenanceDeliverables(), 'status' => TaskStatus::Todo]);
    }, ['deliverables' => maintenanceDeliverables()], 'tasks.one_subtask'],
    'a reused empty draft' => [static function (Project $project): void {
        Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Update dependencies within current constraints', 'brief' => 'Update dependencies. Swamp schedule: routine',
            'status' => 'backlog', 'orchestration' => 'external', 'implementer_model' => 'claude-opus-5-5', 'reviewer_model' => 'claude-opus-5-5']);
    }, ['deliverables' => maintenanceDeliverables()], 'tasks.no_subtasks'],
]);

// The Horizon baseline plan posts a task with no subtask; queue_test.ts pins this body. The Gateway takes it, moves it to todo
// and starts it.
it('creates the Horizon baseline task as a task with no subtask, moves it to todo and starts it', function (): void {
    $project = soloProject();
    $body = json_decode((string) file_get_contents(dirname(base_path(), 2).'/swamp/extensions/behaviors/projects/horizon-new-dawn/fixtures/baseline-task.json'), true, flags: JSON_THROW_ON_ERROR);

    $id = $this->postJson('/api/v1/tasks', [...$body, 'project_id' => $project->id])->assertCreated()
        ->assertJsonPath('data.subtasks', [])->assertJsonPath('data.work.deliverables.0.id', 'compatibility')->json('data.id');
    $this->patchJson("/api/v1/tasks/{$id}", ['status' => 'todo'])->assertOk()->assertJsonPath('data.status', 'todo');
    $start = $this->withHeader('Idempotency-Key', 'horizon-start')->postJson("/api/v1/tasks/{$id}/start")->assertStatus(202);

    expect($start->json('data.kind'))->toBe('instance.create')
        ->and(Task::query()->where('parent_id', $id)->sole()->type)->toBe(TaskType::Work);
});

// A person folds the refused task's one subtask into it; the next run admits it, starts it and clears only that refusal.
it('admits a refused maintenance task once its subtask is folded into it, and clears that refusal', function (): void {
    $project = maintenanceSolo();
    maintenanceRoutine($project, ['tasks' => [['title' => 'Update', 'brief' => 'Update.', 'deliverables' => maintenanceDeliverables()]]]);
    $group = Task::topLevel()->where('project_id', $project->id)->sole();
    expect($group->assistance_requested)->toBeTrue();

    Task::query()->where('parent_id', $group->id)->delete();
    CreateTaskGroupAction::openWork($group, maintenanceDeliverables());
    maintenanceRoutine($project, ['deliverables' => maintenanceDeliverables()]);

    $group->refresh();
    expect($group->status->value)->toBe('reserved')
        ->and($group->assistance_requested)->toBeFalse()
        ->and($group->assistance_reason)->toBeNull()
        ->and(Operation::query()->find($group->maintenance['operation_id'] ?? null)?->kind)->toBe('instance.create');
});

// Each run refuses with the current shape's instruction; a request that is not this refusal stays as it is.
it('refreshes a maintenance refusal with the current error on each run, and keeps any other request', function (): void {
    $project = maintenanceSolo();
    maintenanceRoutine($project, ['tasks' => [['title' => 'Update', 'brief' => 'Update.', 'deliverables' => maintenanceDeliverables()]]]);
    $group = Task::topLevel()->where('project_id', $project->id)->sole();
    expect($group->assistance_reason)->toStartWith('Maintenance task refused in backlog: tasks.one_subtask. ');

    Task::query()->where('parent_id', $group->id)->delete();
    maintenanceRoutine($project, ['deliverables' => maintenanceDeliverables()]);
    expect($group->refresh()->assistance_reason)->toStartWith('Maintenance task refused in backlog: tasks.no_subtasks. ');

    foreach ([1, 2] as $position) {
        Task::query()->create(['parent_id' => $group->id, 'position' => $position, 'title' => 'Part '.$position, 'brief' => 'Part.',
            'deliverables' => $position === 1 ? maintenanceDeliverables() : [], 'status' => TaskStatus::Todo]);
    }
    maintenanceRoutine($project, ['deliverables' => maintenanceDeliverables()]);
    expect($group->refresh()->assistance_reason)->toStartWith('Maintenance task refused in backlog: tasks.subtask_deliverables_missing. ');

    TaskAssistance::apply($group->refresh(), AssistanceKind::Failure, reason: 'Another failure.', replaceFailure: true);
    maintenanceRoutine($project, ['deliverables' => maintenanceDeliverables()]);
    expect($group->refresh()->assistance_reason)->toBe('Another failure.')
        ->and($group->status->value)->toBe('backlog');
});
