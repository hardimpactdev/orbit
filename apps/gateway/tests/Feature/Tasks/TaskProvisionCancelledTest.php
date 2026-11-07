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
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
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
 * A claim as the Gateway admitted it: its group reserved, its slot `provisioning` with the group, and the slot's workspace bound to it.
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

it('runs the group on its slot after a claim that passed', function (): void {
    Queue::fake();
    [$operation, $group, $slot, $instance] = claimedProvision();

    app(TaskProvisionProjection::class)->record($operation, 'succeeded', null, fn (): Operation => $operation);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($slot->refresh()->state)->toBe(TaskVmState::Ready)
        ->and($instance->refresh()->status)->toBe(InstanceState::SourceResolved)->and($instance->starting_commit)->toBe(str_repeat('a', 40));
    Queue::assertNotPushed(ReleaseTaskVmSlot::class);
});

it('releases the slot of a claim that ended cancelled or failed, so the slot is free again', function (string $status, TaskGroupStatus $ended): void {
    Queue::fake();
    [$operation, $group, $slot] = claimedProvision();

    app(TaskProvisionProjection::class)->record($operation, $status, $status === 'failed' ? 'tasks.provision_failed' : null, fn (): Operation => $operation);

    expect($group->refresh()->status)->toBe($ended);
    Queue::assertPushed(ReleaseTaskVmSlot::class, fn (ReleaseTaskVmSlot $job): bool => $job->slotId === $slot->id && $job->groupId === $group->id);
})->with([['cancelled', TaskGroupStatus::Cancelled], ['failed', TaskGroupStatus::Failed]]);

it('leaves the release to a run that releases its slot itself, as a proof run does', function (): void {
    Queue::fake();
    [$operation] = claimedProvision();

    app(TaskProvisionProjection::class)->record($operation, 'failed', 'tasks.provision_failed', fn (): Operation => $operation, release: false);

    Queue::assertNotPushed(ReleaseTaskVmSlot::class);
});
