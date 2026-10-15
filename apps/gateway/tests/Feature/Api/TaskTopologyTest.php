<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTopology;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'topology-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => '10.44.0.90',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
});

it('preserves per-subtask declarations through group creation edits and standalone creation', function (): void {
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $group = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Declared nodes', 'brief' => 'Use private workload nodes.',
        'tasks' => [
            ['title' => 'First', 'brief' => 'Needs dev.', 'topology' => ['app-dev']],
            ['title' => 'Second', 'brief' => 'Also needs dev.', 'topology' => ['app-dev']],
        ],
    ])->assertCreated()->assertJsonPath('data.tasks.0.topology', ['app-dev'])
        ->assertJsonPath('data.tasks.1.topology', ['app-dev'])->json('data');
    $task = Task::query()->findOrFail($group['tasks'][0]['id']);
    expect($task->topology->toArray())->toBe(['app-dev']);
    $url = '/api/v1/task-groups/'.$group['id'].'/tasks';
    $this->patchJson($url.'/'.$task->id, ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.topology', ['app-dev']);
    $this->patchJson($url.'/'.$task->id, ['topology' => []])->assertOk()->assertJsonPath('data.topology', []);
    expect($task->fresh()->topology->toArray())->toBe([]);
    $this->postJson($url, ['title' => 'Third', 'brief' => 'Production.', 'topology' => ['app-prod']])
        ->assertCreated()->assertJsonPath('data.topology', ['app-prod']);
    // app-dev and app-prod are two test nodes; a third, app-prod-2, would not fit a large VM.
    $this->postJson($url, ['title' => 'Fourth', 'brief' => 'Second production node.', 'topology' => ['app-prod', 'app-prod-2']])
        ->assertUnprocessable()->assertJsonPath('error.message', TaskTopology::tooManyMessage());
});

it('rejects invalid declarations without creating a group', function (mixed $topology): void {
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Invalid', 'brief' => 'Invalid inventory.',
        'tasks' => [['title' => 'Step', 'brief' => 'No start.', 'topology' => $topology]],
    ])->assertUnprocessable();
    expect(Task::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'duplicate' => [['app-dev', 'app-dev']], 'gateway' => [['gateway']], 'live host' => [['beast']],
    'scalar' => ['app-dev'], 'null' => [null], 'object' => [['name' => 'app-dev']],
    'node twice with roles' => [['app-prod', 'app-prod+ingress']], 'unknown role' => [['app-prod+router']],
    'conflicting role' => [['app-dev+ingress']], 'role without a Cluster-free path' => [['app-prod+database']],
    'repeated role' => [['app-prod+ingress+ingress']], 'second production node alone' => [['app-prod-2']],
    'three nodes' => [['app-dev', 'app-prod', 'app-prod-2']],
]);

it('stores declared roles and refuses a role that does not fit its node', function (): void {
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $group = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Roles', 'brief' => 'Declared roles.',
        'tasks' => [['title' => 'Ingress', 'brief' => 'Public edge.', 'topology' => ['app-prod+ingress', 'app-dev']]],
    ])->assertCreated()->assertJsonPath('data.tasks.0.topology', ['app-prod+ingress', 'app-dev'])->json('data');

    // ingress conflicts with app-dev, the base role of the node.
    $this->postJson('/api/v1/task-groups/'.$group['id'].'/tasks', ['title' => 'Dev edge', 'brief' => 'Same node.', 'topology' => ['app-dev+ingress']])
        ->assertUnprocessable();
    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Conflict', 'brief' => 'Two subtasks.',
        'tasks' => [
            ['title' => 'One', 'brief' => 'Edge.', 'topology' => ['app-prod+ingress']],
            ['title' => 'Two', 'brief' => 'Data.', 'topology' => ['app-prod+websocket']],
        ],
    ])->assertUnprocessable();
    expect(Task::query()->where('parent_id', $group['id'])->count())->toBe(1);
});

it('keeps declarations locked after a subtask has started even if its status is reset', function (): void {
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Work', 'status' => 'backlog']);
    $task = $group->tasks()->create(['title' => 'Started', 'brief' => 'Work', 'position' => 1, 'status' => 'todo',
        'topology' => ['app-dev'], 'subtask_start_commit' => str_repeat('a', 40)]);
    $this->patchJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id, ['topology' => ['app-prod']])->assertUnprocessable();
    expect($task->fresh()->topology->toArray())->toBe(['app-dev']);
});
