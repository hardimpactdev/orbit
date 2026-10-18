<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;

/** The Pi token of every test task VM. Pi tests read it as the token of the workspace's Node. */
const TEST_TASK_VM_PI_TOKEN = 'pi-node-token-with-more-than-32-characters';

/**
 * Runs a group on its own ready task VM, as every task group does: it owns its workspace `task-<group id>`, and a ready
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
 * Creates a group's workspace as provisioning does: the Instance `task-<group id>` in the group's Project, owned by
 * the group from its first save. `$attach` also attaches it, as a started claim does. `$attributes` add to or
 * replace the defaults.
 *
 * @param  array<string, mixed>  $attributes
 */
function test_task_workspace(Task $group, array $attributes = [], bool $attach = true): Instance
{
    $name = TaskWorkspaceName::for($group);
    $workspace = Instance::query()->create([
        'project_id' => $group->project_id,
        'name' => $name,
        'branch_override' => $name,
        'checkout_path' => '/home/orbit/apps/workspace-'.$group->id,
        'status' => 'source_resolved',
        ...$attributes,
        'task_workspace_owner_id' => $group->id,
    ]);
    if ($attach) {
        $group->taskable()->associate($workspace);
        $group->save();
    }

    return $workspace;
}

/**
 * A task workspace that task operations may reach: a running group of `$project` owns and holds the Instance on
 * `$node`, and the group's ready task VM owns the Node. `$workspace` and `$group` add to or replace the defaults.
 *
 * @param  array<string, mixed>  $workspace
 * @param  array<string, mixed>  $group
 */
function test_ready_task_workspace(Project $project, Node $node, array $workspace, array $group = []): Instance
{
    $owner = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Task',
        'brief' => 'Brief.',
        'status' => TaskGroupStatus::Running,
        ...$group,
    ]);
    $instance = test_task_workspace($owner, ['node_id' => $node->id, 'branch_override' => null, ...$workspace]);
    test_task_vm($owner, $node);

    return $instance->refresh();
}

/**
 * Places a group's workspace as the task VM provisioner does: a ready task VM of the group owns the Node of the
 * workspace `task-<group id>` that the group owns.
 */
function test_vm_workspace(Task $group, Instance $workspace): Instance
{
    if ($workspace->task_workspace_owner_id !== $group->id) {
        throw new LogicException('Create the workspace with test_task_workspace(), as provisioning does.');
    }
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
