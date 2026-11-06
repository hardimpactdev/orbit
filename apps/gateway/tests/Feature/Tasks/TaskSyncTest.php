<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskSyncProjection;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function syncOperation(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-1', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.137', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.137', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));

    return Operation::query()->create(['kind' => OperationKinds::TasksSync, 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $node->id, 'status' => OperationStatus::Running,
        'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
        'input' => ['group_id' => 1, 'workspace' => ['repository' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'branch' => 'task-1']]]);
}

it('records the two commits that the fetch stored', function (): void {
    $main = str_repeat('a', 40);
    $task = str_repeat('b', 40);

    $projected = app(TaskSyncProjection::class)->apply(syncOperation(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Read the refs' => ['status' => 'succeeded']], 'summary' => ['status' => 'succeeded', 'failed_steps' => []], 'refs' => "{$main}\n{$task}\n"],
    ]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['default_branch' => 'main', 'default_commit' => $main, 'branch' => 'task-1', 'branch_commit' => $task]);
});

it('fails with the step that failed and leaves the commits out', function (): void {
    $projected = app(TaskSyncProjection::class)->apply(syncOperation(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Clone the refs' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Clone the refs']]],
        'failure' => ['failed_step' => 'Clone the refs', 'detail' => 'exec failed', 'stderr_recorded' => true],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.sync_failed')
        ->and($projected->result)->toBe(['error_code' => 'tasks.sync_failed', 'failed_step' => 'Clone the refs']);
});
