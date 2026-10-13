<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Task;
use App\Models\TaskVm;

/** The Pi token of every test task VM. Pi tests read it as the token of the workspace's Node. */
const TEST_TASK_VM_PI_TOKEN = 'pi-node-token-with-more-than-32-characters';

/**
 * Runs a group on its own ready task VM, as every task group does once shared mode is gone: the group is `vm`, its
 * workspace is `task-<group id>`, and a ready task VM owns the workspace's Node. A group without a workspace only
 * becomes `vm`. A test provisioner then calls {@see test_vm_workspace()} when the claim provides the workspace.
 */
function test_vm_group(Task $group): Task
{
    $group->forceFill(['task_compute' => TaskCompute::Vm])->save();
    $workspace = $group->taskable;
    if ($workspace instanceof Instance) {
        test_vm_workspace($group, $workspace);
    }

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/**
 * Places a `vm` group's workspace as the task VM provisioner does: the workspace becomes `task-<group id>`, and a
 * ready task VM of the group owns its Node. A shared group's workspace stays as it is.
 */
function test_vm_workspace(Task $group, Instance $workspace): Instance
{
    $group->loadMissing('project');
    if (($group->task_compute ?? $group->project->task_compute) !== TaskCompute::Vm
        || TaskVm::query()->live()->where('group_id', $group->id)->exists()) {
        return $workspace;
    }
    $group->forceFill(['task_compute' => TaskCompute::Vm])->save();
    $workspace->forceFill(['name' => TaskWorkspaceName::for($group), 'source_layout' => $workspace->source_layout ?? 'checkout'])->save();
    test_task_vm($group, $workspace->node);

    return $workspace->refresh();
}

/** A ready task VM of the group on the Node. The Node gets the `app-dev` role, as every task VM Node has. */
function test_task_vm(Task $group, Node $node): TaskVm
{
    NodeRole::query()->firstOrCreate(['node_id' => $node->id, 'role' => RoleName::AppDev], ['status' => LifecycleStatus::Active]);

    return TaskVm::query()->create([
        'group_id' => $group->id, 'node_id' => $node->id, 'provider' => 'upcloud', 'name' => 'tvm-'.$group->id,
        'state' => TaskVmState::Ready, 'wireguard_ip' => $node->wireguard_ip, 'pi_token' => TEST_TASK_VM_PI_TOKEN,
    ]);
}
