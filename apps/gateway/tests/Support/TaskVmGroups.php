<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
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
 * Runs a group on its own ready task VM, as every task group does: its workspace is `task-<group id>`, and a ready
 * task VM owns the workspace's Node. A test provisioner calls {@see test_vm_workspace()} for a group without a
 * workspace when the claim provides one.
 */
function test_vm_group(Task $group): Task
{
    $workspace = $group->taskable;
    if ($workspace instanceof Instance) {
        test_vm_workspace($group, $workspace);
    }

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/**
 * Places a group's workspace as the task VM provisioner does: the workspace becomes `task-<group id>`, and a ready
 * task VM of the group owns its Node.
 */
function test_vm_workspace(Task $group, Instance $workspace): Instance
{
    $workspace->forceFill(['task_workspace_owner_id' => $group->id])->save();
    if (TaskVm::query()->live()->where('group_id', $group->id)->exists()) {
        return $workspace;
    }
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
