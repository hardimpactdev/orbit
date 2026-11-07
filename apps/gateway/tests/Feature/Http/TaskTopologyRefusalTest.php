<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

function topology_refusal_node(): Node
{
    $node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create([
            'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        ]);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:fingerprint', 'agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    return $node;
}

function topology_refusal_project(): Project
{
    return Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
}

/** @param array<string, mixed> $body */
function topology_refusal_send(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->json($method, $uri, $body);
}

/** @return array<string, mixed> */
function topology_refusal_subtask(array $topology): array
{
    return ['title' => 'Step', 'brief' => 'Do it', 'topology' => $topology, 'deliverables' => [['id' => 'doc', 'type' => 'review', 'description' => 'Reviewed']]];
}

describe('a task group cannot declare a topology', function (): void {
    beforeEach(function (): void {
        topology_refusal_node();
        app(TaskExtensionState::class)->enable();
        $this->project = topology_refusal_project();
    });

    it('refuses a new group whose subtask declares a topology', function (): void {
        $response = topology_refusal_send('POST', '/api/v1/task-groups', [
            'project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief',
            'tasks' => [topology_refusal_subtask(['app-dev'])],
        ]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported')
            ->and(Task::topLevel()->count())->toBe(0);
    });

    it('accepts a new group whose subtasks declare no topology', function (): void {
        topology_refusal_send('POST', '/api/v1/task-groups', [
            'project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief',
            'tasks' => [topology_refusal_subtask([])],
        ])->assertStatus(201);

        expect(Task::topLevel()->count())->toBe(1);
    });

    it('refuses a new subtask that declares a topology', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);

        $response = topology_refusal_send('POST', "/api/v1/task-groups/{$group->id}/tasks", topology_refusal_subtask(['app-dev', 'app-prod']));

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported')
            ->and($group->tasks()->count())->toBe(0);
    });

    it('refuses an update that gives a subtask a topology', function (): void {
        $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);
        $subtask = $group->tasks()->create(['title' => 'Step', 'brief' => 'Do it']);

        $response = topology_refusal_send('PATCH', "/api/v1/task-groups/{$group->id}/tasks/{$subtask->id}", ['topology' => ['app-dev']]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported')
            ->and($subtask->fresh()->topology->isEmpty())->toBeTrue();
    });
});
