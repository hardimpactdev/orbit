<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $this->group = fn (): Task => Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Backlog]);
    });

    it('refuses a new group whose subtask declares a topology', function (): void {
        $response = topology_refusal_send('POST', '/api/v1/tasks', [
            'project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief',
            'subtasks' => [topology_refusal_subtask(['app-dev'])],
        ]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported')
            ->and(Task::topLevel()->count())->toBe(0);
    });

    it('refuses any nonempty list by name, also one the old node catalog did not know', function (): void {
        $response = topology_refusal_send('POST', '/api/v1/tasks', [
            'project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief',
            'subtasks' => [topology_refusal_subtask(['app-dev', 'app-prod', 'app-prod-2'])],
        ]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported');
    });

    it('ignores an empty topology on a new group, and stores none', function (): void {
        $response = topology_refusal_send('POST', '/api/v1/tasks', [
            'project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief',
            'subtasks' => [topology_refusal_subtask([])],
        ])->assertStatus(201);

        $group = Task::topLevel()->sole();
        expect($response->json('data.subtasks.0'))->toHaveKey('title')->not->toHaveKey('topology')
            ->and(Schema::hasColumn('tasks', 'topology'))->toBeFalse();
    });

    it('refuses a new subtask that declares a topology', function (array $topology): void {
        $group = ($this->group)();

        $response = topology_refusal_send('POST', '/api/v1/tasks', ['parent_id' => $group->id, ...topology_refusal_subtask($topology)]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported')
            ->and($group->tasks()->count())->toBe(0);
    })->with(['valid' => [['app-dev', 'app-prod']], 'unknown node' => [['x']]]);

    it('fails validation for a topology that is not a list', function (mixed $topology): void {
        $group = ($this->group)();

        $response = topology_refusal_send('POST', '/api/v1/tasks', ['parent_id' => $group->id, ...topology_refusal_subtask([]), 'topology' => $topology]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('validation.failed')
            ->and($group->tasks()->count())->toBe(0);
    })->with(['string' => ['x'], 'null' => [null]]);

    it('ignores an empty topology on a new subtask', function (): void {
        $group = ($this->group)();

        $response = topology_refusal_send('POST', '/api/v1/tasks', ['parent_id' => $group->id, ...topology_refusal_subtask([])])->assertStatus(201);

        expect($response->json('data'))->toHaveKey('title')->not->toHaveKey('topology')
            ->and(Schema::hasColumn('tasks', 'topology'))->toBeFalse();
    });

    it('refuses an update that gives a subtask a topology', function (array $topology): void {
        $group = ($this->group)();
        $subtask = $group->tasks()->create(['title' => 'Step', 'brief' => 'Do it']);

        $response = topology_refusal_send('PATCH', "/api/v1/tasks/{$subtask->id}", ['topology' => $topology]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('tasks.orchestration_unsupported')
            ->and(Schema::hasColumn('tasks', 'topology'))->toBeFalse();
    })->with(['valid' => [['app-dev']], 'unknown node' => [['x']]]);

    it('ignores an update that sends an empty topology', function (): void {
        $group = ($this->group)();
        $subtask = $group->tasks()->create(['title' => 'Step', 'brief' => 'Do it', 'position' => 1]);

        $response = topology_refusal_send('PATCH', "/api/v1/tasks/{$subtask->id}", ['topology' => [], 'title' => 'Renamed']);

        $response->assertOk();
        expect($response->json('data.title'))->toBe('Renamed')
            ->and($response->json('data'))->not->toHaveKey('topology');
    });

    it('has no column for a topology and shows none on a group\'s subtasks', function (): void {
        $group = ($this->group)();
        foreach (['First', 'Second'] as $index => $title) {
            $group->tasks()->create(['title' => $title, 'brief' => 'Do it', 'position' => $index + 1]);
        }
        expect(Schema::hasColumn('tasks', 'topology'))->toBeFalse();

        $response = topology_refusal_send('POST', '/api/v1/tasks', ['parent_id' => $group->id, ...topology_refusal_subtask([])]);

        $response->assertStatus(201);
        expect($response->json('data'))->toHaveKey('title')->not->toHaveKey('topology');
        $show = topology_refusal_send('GET', "/api/v1/tasks/{$group->id}")->assertOk();
        expect($show->json('data.subtasks'))->toHaveCount(3)
            ->and(array_column($show->json('data.subtasks'), 'topology'))->toBe([]);
    });
});
