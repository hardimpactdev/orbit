<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** docs/cli/task/turn.mmd: work on a group whose provision waits or runs names that Operation instead of a busy target. */
beforeEach(function (): void {
    SwampNodeFixture::ensure();
    $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->group = Task::query()->create(['project_id' => $project->id, 'title' => 'Manual group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo,
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $this->subtask = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Build it', 'brief' => 'Brief', 'status' => 'todo',
        'deliverables' => [['id' => 'plan', 'type' => 'review', 'description' => 'Reviewed.']]]);
});

function provision_operation(Task $group, OperationStatus $status): Operation
{
    return Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => null,
        'input' => ['capacity_request' => ['commit' => str_repeat('c', 40)]], 'status' => $status]);
}

$calls = [
    'turn' => [fn (Task $group, Task $subtask): array => ["/api/v1/tasks/{$subtask->id}/turn", ['role' => 'implementer', 'prompt' => 'Start.', 'thread' => 'new']]],
    'check' => [fn (Task $group, Task $subtask): array => ["/api/v1/tasks/{$subtask->id}/check", ['phase' => 'baseline']]],
    'publish' => [fn (Task $group, Task $subtask): array => ["/api/v1/tasks/{$group->id}/publish", ['check' => 1]]],
    'script' => [fn (Task $group, Task $subtask): array => ["/api/v1/tasks/{$group->id}/script", ['subtask' => $subtask->id, 'script' => 'bin/check', 'args' => []]]],
    'package' => [fn (Task $group, Task $subtask): array => ["/api/v1/tasks/{$group->id}/package", ['manager' => 'composer', 'package' => 'acme/tools']]],
    'sync' => [fn (Task $group, Task $subtask): array => ["/api/v1/tasks/{$group->id}/sync", []]],
];

it('refuses work on a group whose provision still waits or runs with tasks.not_provisioned and the provision Operation', function (Closure $call, OperationStatus $status): void {
    $provision = provision_operation($this->group, $status);
    [$uri, $body] = $call($this->group, $this->subtask);

    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->withHeader('Idempotency-Key', 'manual-1')->postJson($uri, $body)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'tasks.not_provisioned')
        ->assertJsonPath('error.details.operation_id', $provision->id);
})->with($calls)->with([
    'waiting for a place' => [OperationStatus::Waiting],
    'running' => [OperationStatus::Running],
]);

it('refuses every other admission through the shared group gate', function (string $kind): void {
    $provision = provision_operation($this->group, OperationStatus::Waiting);

    expect(fn () => app(TaskOperations::class)->start($this->group, $kind, [], static function (): void {}, 'test', null))
        ->toThrow(fn (ResourceOperationException $refusal) => expect([$refusal->errorCode, $refusal->details])->toBe(['tasks.not_provisioned', ['operation_id' => $provision->id]]));
})->with(['tasks.build', 'tasks.review_round', 'tasks.prep_fix', 'tasks.maintenance']);

it('returns a keyed replay before the refusal', function (): void {
    $sync = Operation::query()->create(['kind' => 'tasks.sync', 'target_type' => 'task_group', 'target_id' => $this->group->id, 'node_id' => null,
        'input' => [], 'status' => OperationStatus::Succeeded, 'idempotency_key' => 'sync-1', 'idempotency_fingerprint' => 'fingerprint']);
    provision_operation($this->group, OperationStatus::Waiting);

    $replay = app(OperationIdempotency::class)->under('tasks.sync', 'sync-1', 'fingerprint',
        fn (): Operation => app(TaskOperations::class)->start($this->group, 'tasks.sync', [], static function (): void {}, 'test', null));

    expect($replay->id)->toBe($sync->id);
});

it('keeps operation.target_busy for another open Operation on the group', function (): void {
    $held = Operation::query()->create(['kind' => 'tasks.sync', 'target_type' => 'task_group', 'target_id' => $this->group->id, 'node_id' => null,
        'input' => [], 'status' => OperationStatus::Running]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->withHeader('Idempotency-Key', 'manual-2')
        ->postJson("/api/v1/tasks/{$this->subtask->id}/turn", ['role' => 'implementer', 'prompt' => 'Start.', 'thread' => 'new'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'operation.target_busy')
        ->assertJsonPath('error.details.operation_id', $held->id);
});
