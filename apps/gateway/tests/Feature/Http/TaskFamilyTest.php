<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStartCommit;
use App\Domain\Tasks\TaskStatus;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

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
    $this->patchJson('/api/v1/tasks/'.$this->parent->id, ['status' => 'todo'])->assertOk()->assertJsonPath('data.status', 'todo');
    expect(Operation::query()->count())->toBe(0);
});

it('refuses the wrong level for VM and child actions', function (string $method, string $suffix, string $level): void {
    $id = $level === 'parent' ? $this->parent->id : $this->child->id;
    $this->withHeader('Idempotency-Key', 'level.test')->json($method, '/api/v1/tasks/'.$id.$suffix)->assertConflict()->assertJsonPath('error.code', 'task.level_invalid');
})->with([
    ['POST', '/start', 'child'], ['POST', '/park', 'child'], ['POST', '/publish', 'child'], ['POST', '/complete', 'child'],
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

it('keeps start capacity refusal and leaves todo admission unchanged', function (): void {
    app()->instance(TaskStartCommit::class, new class implements TaskStartCommit
    {
        public function resolve(Project $project): string
        {
            return str_repeat('a', 40);
        }
    });
    $this->parent->update(['status' => TaskGroupStatus::Todo]);
    $this->postJson('/api/v1/tasks/'.$this->parent->id.'/start')
        ->assertConflict()->assertJsonPath('error.code', 'tasks.capacity_unavailable');
    expect($this->parent->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and(Operation::query()->count())->toBe(0);
});

it('filters children by status and rejects fields from the other create level', function (): void {
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&status=completed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/tasks?parent_id='.$this->parent->id.'&status=todo')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/tasks', ['parent_id' => $this->parent->id, 'title' => 'Child', 'brief' => 'Brief', 'preview' => true])->assertUnprocessable();
    $this->postJson('/api/v1/tasks', ['project_id' => $this->project->id, 'title' => 'Parent', 'brief' => 'Brief', 'deliverables' => []])->assertUnprocessable();
});
