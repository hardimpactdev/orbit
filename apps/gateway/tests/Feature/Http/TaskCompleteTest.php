<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns 409 with valid retry advice when complete asks a running group Operation to stop', function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Published group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/77']);
    $operation = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id,
        'status' => OperationStatus::Running, 'attempts' => 1, 'input' => []]);

    $this->postJson("/api/v1/tasks/{$group->id}/complete")->assertConflict()
        ->assertJsonPath('error.code', 'operation.target_busy')
        ->assertJsonPath('error.details.operation_id', $operation->id)
        ->assertJsonPath('error.message', "The group's running Operation was asked to stop; retry this request when it has ended.");

    expect($operation->refresh()->cancel_requested_at)->not->toBeNull()
        ->and($operation->status)->toBe(OperationStatus::Running)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Settling);

    $operation->update(['status' => OperationStatus::Cancelled, 'finished_at' => now()]);

    $this->withHeader('Idempotency-Key', 'complete-recovery-cancel')->postJson("/api/v1/tasks/{$group->id}/cancel")
        ->assertConflict()->assertJsonPath('error.code', 'tasks.not_cancellable');
    $this->postJson("/api/v1/tasks/{$group->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed);
});
