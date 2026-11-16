<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/**
 * An old claim as the Gateway admitted it before the task VMs were removed: its group reserved, its slot `provisioning` with the group,
 * and the slot's workspace bound to it. No claim starts any more; an open one still ends through the projection.
 *
 * @return array{Operation, Task, TaskVm, Instance}
 */
function claimedProvision(): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('shark-tvm-1', '10.44.0.133');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved]);
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'shark-tvm-1',
        'slot_number' => 1, 'slot_project_id' => $project->id, 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.133', 'size' => TaskVmSize::Large]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'task-1', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::Reserved, 'first_setup_pending' => false]);
    $group->taskable()->associate($instance)->save();
    $operation = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => ['task_vm_id' => $slot->id, 'node_id' => $host->id, 'group_id' => $group->id, 'instance_id' => $instance->id,
            'workspace' => ['branch' => 'task-'.$group->id, 'start_commit' => str_repeat('a', 40)]]]);

    return [$operation, $group, $slot, $instance];
}

it('runs the group after a claim that passed and leaves its old slot row as it is', function (): void {
    Queue::fake();
    [$operation, $group, $slot, $instance] = claimedProvision();

    app(TaskProvisionProjection::class)->record($operation, 'succeeded', null, fn (): Operation => $operation);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($slot->refresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($instance->refresh()->status)->toBe(InstanceState::SourceResolved)->and($instance->starting_commit)->toBe(str_repeat('a', 40));
    Queue::assertNothingPushed();
});

it('ends the group of a claim that ended cancelled or failed and starts no release, as no task VM release is left', function (string $status, TaskGroupStatus $ended): void {
    Queue::fake();
    [$operation, $group, $slot] = claimedProvision();

    app(TaskProvisionProjection::class)->record($operation, $status, $status === 'failed' ? 'tasks.provision_failed' : null, fn (): Operation => $operation);

    expect($group->refresh()->status)->toBe($ended)->and($slot->refresh()->state)->toBe(TaskVmState::Provisioning)
        ->and(Operation::query()->whereKeyNot($operation->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([['cancelled', TaskGroupStatus::Cancelled], ['failed', TaskGroupStatus::Failed]]);

it('fails the group when the Gateway refused the start, so the group does not stay reserved', function (): void {
    Queue::fake();
    [$operation, $group, $slot, $instance] = claimedProvision();
    $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'operation.execution_secrets_invalid']);

    app(TaskProvisionProjection::class)->refused($operation);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
        ->and($instance->refresh()->error_code)->toBe('operation.execution_secrets_invalid')->and($slot->refresh()->state)->toBe(TaskVmState::Provisioning);
    Queue::assertNothingPushed();
});
