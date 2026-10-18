<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskWorkspaceLeaseRecord;

it('reports the workspace holder and ordered waiters without changing ownership while tasks is disabled', function (): void {
    $node = $this->markAsGateway(Node::query()->create([
        'name' => 'lease-status-gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.83', 'wireguard_ip' => '10.44.0.83',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'code' => 'ORB',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Workspace ownership',
        'brief' => 'Serialize the workspace.', 'status' => 'running']);
    $instance = Instance::query()->create(['task_workspace_owner_id' => $group->id, 'project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'lease-status', 'checkout_path' => '/private/lease-status', 'status' => 'source_resolved']);
    $group->taskable()->associate($instance);
    $group->save();
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'reviewer', 'review:1');
    foreach (['check:1', 'publication:1'] as $owner) {
        expect(fn () => $leases->acquire($group, str_starts_with($owner, 'check:') ? 'check' : 'publication', $owner))
            ->toThrow(TaskWorkspaceWaiting::class);
    }
    $holder->update(['remote_uncertain' => true]);
    $before = TaskWorkspaceLeaseRecord::query()->orderBy('id')->get()->toArray();
    $this->getJson('/api/v1/tasks/status')->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.workspace_leases.0.instance_id', $instance->id)
        ->assertJsonPath('data.workspace_leases.0.instance_name', 'lease-status')
        ->assertJsonPath('data.workspace_leases.0.group_id', $group->id)
        ->assertJsonPath('data.workspace_leases.0.project_code', 'ORB')
        ->assertJsonPath('data.workspace_leases.0.holder.id', $holder->id)
        ->assertJsonPath('data.workspace_leases.0.holder.kind', 'reviewer')
        ->assertJsonPath('data.workspace_leases.0.holder.remote_uncertain', true)
        ->assertJsonPath('data.workspace_leases.0.waiters.0.remote_uncertain', false)
        ->assertJsonPath('data.workspace_leases.0.waiters.0.owner', 'check:1')
        ->assertJsonPath('data.workspace_leases.0.waiters.1.owner', 'publication:1');
    expect(TaskWorkspaceLeaseRecord::query()->orderBy('id')->get()->toArray())->toBe($before);

});
