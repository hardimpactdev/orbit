<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/**
 * docs/reference/tasks.md#cancel-a-stuck-task: a task with no VM and no workspace has nothing to release, so the cancel ends it at
 * admission (R1). The manual-mode trial of 9 November read `todo` from task:show while a no-op Swamp run was still being projected.
 */
beforeEach(function (): void {
    SwampNodeFixture::ensure();
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->group = Task::query()->create(['project_id' => $project->id, 'title' => 'Manual group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo,
        'capacity_wait_reason' => 'task places full']);
    $this->subtask = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Build it', 'brief' => 'Brief', 'status' => 'todo',
        'deliverables' => [['id' => 'plan', 'type' => 'review', 'description' => 'Reviewed.']]]);
});

function cancel_task(Task $group, string $key): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key)->postJson("/api/v1/tasks/{$group->id}/cancel");
}

it('ends a todo task at admission, so the 202 and the next task:show agree', function (): void {
    $response = cancel_task($this->group, 'cancel-1')->assertStatus(202)
        ->assertJsonPath('data.kind', 'tasks.cancel')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result', ['group_id' => $this->group->id, 'instance_id' => null, 'released' => true])
        ->assertJsonPath('data.poll_after_seconds', null);
    $operation = Operation::query()->findOrFail($response->json('data.id'));

    expect($operation->state['swamp_run_id'] ?? null)->toBeNull()->and($operation->lock_key)->toBeNull();
    $this->getJson("/api/v1/tasks/{$this->group->id}")->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.capacity_wait_reason', null)
        ->assertJsonPath('data.subtasks.0.status', 'cancelled');

    // A lost reply repeats the key and gets the same Operation; the group stays cancelled.
    cancel_task($this->group, 'cancel-1')->assertStatus(202)->assertJsonPath('data.id', $operation->id);
    expect(Operation::query()->where('kind', 'tasks.cancel')->count())->toBe(1)
        ->and($this->group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($this->subtask->refresh()->status)->toBe(TaskStatus::Cancelled);
});

it('cancels the provision that waits for a place first, then ends the task at admission', function (): void {
    $provision = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $this->group->id, 'node_id' => null,
        'input' => ['capacity_request' => ['commit' => str_repeat('c', 40)]], 'status' => OperationStatus::Waiting]);

    cancel_task($this->group, 'cancel-2')->assertStatus(202)->assertJsonPath('data.status', 'succeeded');

    expect($provision->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($this->group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});
