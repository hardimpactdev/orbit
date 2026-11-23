<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->parent = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Parent', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);
    $this->child = $this->parent->tasks()->create(['position' => 1, 'title' => 'Child', 'brief' => 'Brief', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done']]]);
});

it('reads either level and lists children by parent without renumbering', function (): void {
    $this->getJson('/api/v1/tasks/'.$this->parent->id)->assertOk()->assertJsonPath('data.parent_id', null)->assertJsonPath('data.subtasks.0.id', $this->child->id)->assertJsonMissingPath('data.tasks');
    $this->getJson('/api/v1/tasks/'.$this->child->id)->assertOk()->assertJsonPath('data.parent_id', $this->parent->id);
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->child->id);
    $this->getJson('/api/v1/tasks/status')->assertOk();
});

it('creates either level through the collection and inherits the parent project', function (): void {
    $this->postJson('/api/v1/tasks', ['parent_id' => $this->parent->id, 'title' => 'Second', 'brief' => 'Brief'])->assertCreated()->assertJsonPath('data.parent_id', $this->parent->id);
    $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Another parent', 'brief' => 'Brief'])->assertCreated()->assertJsonPath('data.parent_id', null)->assertJsonPath('data.status', 'backlog');
    expect(Operation::query()->count())->toBe(0);
});

it('filters children through their parent project', function (): void {
    $otherProject = Project::query()->create(['name' => 'other', 'slug' => 'other', 'repository_url' => 'https://github.com/hardimpactdev/other.git', 'default_branch' => 'main', 'root' => 'public']);

    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&project_id='.$this->project->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->child->id);
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&project_id='.$otherProject->id)
        ->assertOk()->assertJsonCount(0, 'data');
});

it('refuses deeper hierarchy and a contradictory project', function (): void {
    $this->postJson('/api/v1/tasks', ['parent_id' => $this->child->id, 'title' => 'Nested', 'brief' => 'Brief'])->assertUnprocessable();
    $this->postJson('/api/v1/tasks', ['parent_id' => $this->parent->id, 'project_id' => $this->project->id + 100, 'title' => 'Foreign', 'brief' => 'Brief'])->assertUnprocessable();
    $this->getJson('/api/v1/tasks?parent_id='.$this->child->id)->assertUnprocessable();
});

it('updates each level without starting work when the parent moves to todo', function (): void {
    $this->patchJson('/api/v1/tasks/'.$this->child->id, ['title' => 'Edited child'])->assertOk()->assertJsonPath('data.title', 'Edited child');
    // A task leaves backlog with no subtask or at least two.
    $this->parent->tasks()->create(['position' => 2, 'title' => 'Second', 'brief' => 'Brief', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done']]]);
    $this->patchJson('/api/v1/tasks/'.$this->parent->id, ['status' => 'todo'])->assertOk()->assertJsonPath('data.status', 'todo');
    expect(Operation::query()->count())->toBe(0);
});

it('refuses the wrong level for VM and child actions', function (string $method, string $suffix, string $level): void {
    $id = $level === 'parent' ? $this->parent->id : $this->child->id;
    $this->withHeader('Idempotency-Key', 'level.test')->json($method, '/api/v1/tasks/'.$id.$suffix)->assertConflict()->assertJsonPath('error.code', 'task.level_invalid');
})->with([
    ['POST', '/start', 'child'], ['POST', '/publish', 'child'], ['POST', '/complete', 'child'],
    ['POST', '/check', 'parent'], ['POST', '/turn', 'parent'], ['POST', '/check/cancel', 'parent'], ['DELETE', '', 'parent'], ['GET', '/agents', 'child'],
]);

it('destroys only a child while its parent is in backlog', function (): void {
    $this->deleteJson('/api/v1/tasks/'.$this->child->id)->assertOk()->assertJsonPath('data.parent_id', $this->parent->id);
    expect($this->parent->fresh())->not->toBeNull()->and(Task::query()->find($this->child->id))->toBeNull();
});

it('cancels the child alone without requiring parent operation admission', function (): void {
    $this->postJson('/api/v1/tasks/'.$this->child->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($this->parent->fresh()->status)->toBe(TaskGroupStatus::Backlog)->and(Operation::query()->count())->toBe(0);
});

it('keeps parent assistance fenced by open children and retains both histories', function (): void {
    $body = ['type' => 'assistance_requested', 'body' => 'Direction needed', 'author' => 'driver'];
    $this->postJson('/api/v1/tasks/'.$this->parent->id.'/comments', $body)->assertConflict()->assertJsonPath('error.code', 'tasks.assistance_subtask_open');
    $this->postJson('/api/v1/tasks/'.$this->child->id.'/comments', $body)->assertCreated();
    $this->child->update(['status' => TaskStatus::Completed]);
    $this->postJson('/api/v1/tasks/'.$this->parent->id.'/comments', $body)->assertCreated();
    $this->getJson('/api/v1/tasks/'.$this->parent->id.'/comments')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/tasks/'.$this->child->id.'/comments')->assertOk()->assertJsonCount(1, 'data');
    expect(Operation::query()->count())->toBe(0);
});

it('preserves existing operation fingerprint identities for direct child selectors', function (): void {
    $fingerprints = app(OperationRequestFingerprint::class);
    $start = new StartsOperation('tasks.check');
    expect($fingerprints->make(null, 'task:check', ['task' => $this->child], '{"phase":"baseline"}', $start))
        ->toBe($fingerprints->make(null, 'tasks:check', ['group' => $this->parent], json_encode(['subtask' => $this->child->id, 'phase' => 'baseline']), $start));
    $start = new StartsOperation('tasks.provision');
    expect($fingerprints->make(null, 'task:start', ['task' => $this->parent], '', $start))
        ->toBe($fingerprints->make(null, 'tasks:provision', ['group' => $this->parent], '', $start));
});

it('removes the old endpoint and command namespaces', function (): void {
    $this->getJson('/api/v1/task-groups')->assertNotFound();
    $this->postJson('/api/v1/task-groups/'.$this->parent->id.'/provision')->assertNotFound();
    expect(collect(app('router')->getRoutes())->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'tasks:')))->toBeEmpty();
});

it('refuses to start a group of a Project without a container default by name, and records nothing', function (): void {
    SwampNodeFixture::ensure();
    Queue::fake();
    $this->parent->update(['status' => TaskGroupStatus::Todo]);
    $this->postJson('/api/v1/tasks/'.$this->parent->id.'/start')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tasks.vm_runtime_removed')
        ->assertJsonPath('error.message', "Task VMs are removed. A task runs only in a copy of its Project's default Instance, and that default must be a container. Move a checkout default into a container with instance:migrate, or create a container default with instance:create; then start the task and work in its copy with task:turn, task:check and task:publish.");
    expect($this->parent->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($this->parent->fresh()->taskable_id)->toBeNull()
        ->and(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

// `task:prep`, `task:prep-fix`, the package install and the repository script are removed with the Pi task loop
// (docs/reference/old-paths.md): no route, no MCP tool. No CLI or SDK release ever called them.
it('has no prep, prep-fix, package or script route: a call answers 404 and records nothing', function (string $suffix, array $body): void {
    SwampNodeFixture::ensure();
    Queue::fake();
    $this->parent->update(['status' => TaskGroupStatus::Running]);
    $this->withHeader('Idempotency-Key', 'pi.'.trim($suffix, '/'))->postJson('/api/v1/tasks/'.$this->parent->id.$suffix, $body)
        ->assertNotFound();
    expect(Operation::query()->count())->toBe(0)
        ->and(collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter()->values()->all())
        ->not->toContain('task:prep')->not->toContain('task:prep-fix')->not->toContain('task:package')->not->toContain('task:script');
    Queue::assertNothingPushed();
})->with([
    'prep' => ['/prep', ['command' => 'firewall:deny']],
    'prep-fix' => ['/prep-fix', ['corrections' => 'Fix the chart.']],
    'package' => ['/package', ['manager' => 'composer', 'package' => 'acme/private']],
    'script' => ['/script', ['subtask' => 1, 'script' => 'bin/test', 'args' => []]],
]);

// Groups that the Pi loop left in its stages, such as a `todo` group and a `waiting_for_review` group whose pull request merged,
// still read, list, cancel and complete after their kinds left the registry.
function piLoopGroup(Project $project, TaskGroupStatus $status, array $fields = []): Task
{
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Prep', 'brief' => 'Brief', 'status' => $status, ...$fields]);
    $group->tasks()->create(['position' => 1, 'title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed,
        'deliverables' => [['id' => 'spec', 'type' => 'review', 'description' => 'The spec is right.']]]);
    foreach (['tasks.prep' => 'succeeded', 'tasks.prep_fix' => 'failed', 'tasks.build' => 'cancelled', 'tasks.review_round' => 'failed',
        'tasks.turn' => 'succeeded', 'tasks.script' => 'failed', 'tasks.package' => 'cancelled', 'tasks.maintenance' => 'failed', 'tasks.idle_save' => 'succeeded'] as $kind => $state) {
        Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => null, 'status' => $state,
            'input' => ['command' => 'firewall:deny'], 'started_at' => now()->subDays(7), 'finished_at' => now()->subDays(7)]);
    }

    return $group;
}

it('reads, lists and cancels a todo group whose runs are all of the removed Pi loop kinds', function (): void {
    $group = piLoopGroup($this->project, TaskGroupStatus::Todo);

    $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()->assertJsonPath('data.status', 'todo');
    expect(array_column($this->getJson('/api/v1/tasks?project_id='.$this->project->id)->assertOk()->json('data'), 'id'))->toContain($group->id);
    $this->getJson('/api/v1/tasks/status')->assertOk();
    $this->getJson('/api/v1/operations?kind=tasks.prep')->assertOk()->assertJsonPath('data.0.kind', 'tasks.prep');
    $this->getJson('/api/v1/operations?kind=tasks.turn')->assertOk()->assertJsonPath('data.0.kind', 'tasks.turn');
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents')->assertOk();

    SwampNodeFixture::ensure();
    Queue::fake();
    $cancel = $this->withHeader('Idempotency-Key', 'pi-cancel')->postJson('/api/v1/tasks/'.$group->id.'/cancel');

    // The old rows hold no lock: the group ends and the cancel run is admitted.
    $cancel->assertAccepted()->assertJsonPath('data.kind', 'tasks.cancel');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('completes a waiting-for-review group of the Pi loop whose pull request merged', function (): void {
    $group = piLoopGroup($this->project, TaskGroupStatus::WaitingForReview, ['orchestration' => 'external', 'watched_pr_number' => 3275,
        'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/3275', 'watched_pr_completion' => 'merged']);

    $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()->assertJsonPath('data.status', 'waiting_for_review');
    $this->postJson('/api/v1/tasks/'.$group->id.'/complete')->assertSuccessful();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed);
});

// A group the Pi loop left with a running subtask whose implementer thread ran on a task VM, and whose pull request merged: the VM
// and its Pi server are gone, so a completion has nothing to interrupt and ends the group.
it('completes a group whose running subtask has a thread of the removed Pi turn', function (): void {
    $group = piLoopGroup($this->project, TaskGroupStatus::Running, ['orchestration' => 'external', 'watched_pr_number' => 3280,
        'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/3280', 'watched_pr_completion' => 'merged']);
    $subtask = $group->tasks()->firstOrFail();
    $vm = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-598', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.137', 'wireguard_ip' => '10.44.0.137', 'user' => 'orbit']));
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $vm->id, 'driver' => 'pi',
        'runtime_key' => 'node:'.$vm->id, 'external_id' => 'session-598', 'role' => 'implementer', 'model' => 'gpt-6.1-sol']);
    $subtask->update(['status' => TaskStatus::Running, 'implementer_agent_thread_id' => $thread->id]);

    $this->getJson('/api/v1/tasks/'.$group->id.'/agents')->assertOk()->assertJsonPath('data.0.driver', 'pi');
    $this->postJson('/api/v1/tasks/'.$group->id.'/complete')->assertSuccessful();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed);
});

// The turn route's kind is `tasks.copy_turn` since the Pi turn left; a retry keeps the fingerprint it had under `tasks.turn`.
it('fingerprints a turn by its group and subtask, as under the old turn kind', function (): void {
    $fingerprints = app(OperationRequestFingerprint::class);
    $start = new StartsOperation('tasks.copy_turn');

    expect($fingerprints->make(null, 'task:turn', ['task' => $this->child], '{"role":"implementer","prompt":"Go."}', $start))
        ->toBe($fingerprints->make(null, 'tasks:turn', ['group' => $this->parent], json_encode(['role' => 'implementer', 'prompt' => 'Go.', 'subtask' => $this->child->id]), $start))
        ->and(StartsOperation::forRoute(app('router')->getRoutes()->getByName('task:turn'))?->kind)->toBe('tasks.copy_turn');
});

it('filters children by status and rejects fields from the other create level', function (): void {
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&status=completed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&status=todo')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/tasks', ['parent_id' => $this->parent->id, 'title' => 'Child', 'brief' => 'Brief', 'preview' => true])->assertUnprocessable();
    $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Parent', 'brief' => 'Brief', 'topology' => []])->assertUnprocessable();
});

it('lists the groups of one pull request by repository and number, watched or published, in any case, and none for another', function (): void {
    $published = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Published', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
        'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/12', 'pr_opened_at' => '2026-11-17T10:00:00Z']);
    $watched = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Watched', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
        'watched_pr_url' => 'https://github.com/HardImpactDev/Orbit/pull/34', 'watched_pr_number' => 34]);
    $find = fn (string $query): array => $this->getJson('/api/v1/tasks?'.$query)->assertOk()->json('data');

    expect(array_column($find('repository=hardimpactdev/orbit&pull_request=12'), 'id'))->toBe([$published->id])
        ->and($find('repository=hardimpactdev/orbit&pull_request=12')[0]['pr_opened_at'])->toBe('2026-11-17T10:00:00+00:00')
        ->and(array_column($find('repository=HARDIMPACTDEV/orbit&pull_request=34'), 'id'))->toBe([$watched->id])
        ->and($find('repository=hardimpactdev/orbit&pull_request=1'))->toBe([])
        ->and($find('repository=other/orbit&pull_request=34'))->toBe([]);
    $this->getJson('/api/v1/tasks?repository=hardimpactdev/orbit')->assertUnprocessable();
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&repository=hardimpactdev/orbit&pull_request=12')->assertUnprocessable();
});

it('reads the pull request filter through the URL indexes', function (): void {
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query;
    });
    $this->getJson('/api/v1/tasks?repository=hardimpactdev/orbit&pull_request=12')->assertOk();
    $list = collect($queries)->first(static fn ($query): bool => str_contains($query->sql, 'lower(pr_url)'));
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$list->sql, $list->bindings))->pluck('detail')->implode("\n");

    expect($plan)->toContain('USING INDEX tasks_pr_url_lower_index')->toContain('USING INDEX tasks_watched_pr_url_lower_index')
        ->not->toContain('SCAN tasks');
});
