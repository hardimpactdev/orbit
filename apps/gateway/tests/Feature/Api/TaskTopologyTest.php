<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
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
