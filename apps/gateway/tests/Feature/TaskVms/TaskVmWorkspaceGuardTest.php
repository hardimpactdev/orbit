<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;

function task_vm_node(string $name, int $octet): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => "192.0.2.{$octet}", 'wireguard_ip' => "10.44.0.{$octet}", 'user' => 'orbit',
    ]);
}

function task_vm_group(Project $project): Task
{
    return Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo']);
}

function task_vm_row(Task $group, Node $host, ?Node $node, TaskVmState $state = TaskVmState::Ready, string $wireguardIp = '10.44.0.130'): TaskVm
{
    return TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $node?->id, 'provider' => 'incus',
        'name' => 'tvm-'.$group->id.'-'.$state->value, 'state' => $state, 'wireguard_ip' => $wireguardIp, 'pi_token' => 'pi-token',
    ]);
}

/** An unsaved Instance: placement reads only its Node, Project and name. */
function task_vm_instance(Project $project, Node $node, string $name, ?int $owner = null): Instance
{
    return new Instance()->forceFill(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'task_workspace_owner_id' => $owner]);
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->other = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main']);
    $this->host = task_vm_node('beast', 7);
    $this->vmNode = task_vm_node('tvm-1', 20);
    $this->shared = task_vm_node('app-dev', 21);
    $this->group = task_vm_group($this->project);
});

describe('forNode', function (): void {
    it('finds the live task VM of a Node', function (): void {
        $vm = task_vm_row($this->group, $this->host, $this->vmNode);

        expect(TaskVm::forNode($this->vmNode)?->is($vm))->toBeTrue()
            ->and(TaskVm::forNode($this->host))->toBeNull()
            ->and(TaskVm::forGroup($this->group)?->is($vm))->toBeTrue();
    });

    it('ignores destroyed task VMs', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode, TaskVmState::Destroyed);

        expect(TaskVm::forNode($this->vmNode))->toBeNull()
            ->and(TaskVm::forGroup($this->group))->toBeNull();
    });
});

describe('assertInstance', function (): void {
    it('allows the group workspace on its task VM and any Instance on other Nodes', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode, TaskVmState::Provisioning);

        TaskVmWorkspaceGuard::assertInstance(task_vm_instance($this->project, $this->vmNode, 'task-'.$this->group->id, $this->group->id));
        TaskVmWorkspaceGuard::assertInstance(task_vm_instance($this->other, $this->shared, 'main'));

        expect(true)->toBeTrue();
    });

    it('refuses any other Instance on a task VM Node', function (string $project, ?string $owner): void {
        task_vm_row($this->group, $this->host, $this->vmNode);
        $instance = task_vm_instance($this->{$project}, $this->vmNode, 'task-'.$this->group->id, match ($owner) {
            'group' => $this->group->id,
            'other' => $this->group->id + 1,
            default => null,
        });

        expect(fn () => TaskVmWorkspaceGuard::assertInstance($instance))
            ->toThrow(TaskVmException::class, "Node [{$this->vmNode->id}] is a task VM and serves only the workspace of task group [{$this->group->id}].");
    })->with([
        'other Project' => ['other', 'group'],
        'named and branched like the workspace without an owner' => ['project', null],
        'other group workspace' => ['project', 'other'],
    ]);

    it('still protects a task VM that is being destroyed or failed', function (TaskVmState $state): void {
        task_vm_row($this->group, $this->host, $this->vmNode, $state);

        expect(fn () => TaskVmWorkspaceGuard::assertInstance(task_vm_instance($this->other, $this->vmNode, 'main')))
            ->toThrow(TaskVmException::class);
    })->with([TaskVmState::Destroying, TaskVmState::Failed]);
});

describe('assertWorkspace', function (): void {
    it('accepts a vm group workspace on its ready task VM', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode);

        TaskVmWorkspaceGuard::assertWorkspace($this->group, task_vm_instance($this->project, $this->vmNode, 'task-'.$this->group->id, $this->group->id));

        expect(true)->toBeTrue();
    });

    it('refuses a vm group workspace that is not on its ready task VM', function (?TaskVmState $state, string $node, bool $owned): void {
        if ($state instanceof TaskVmState) {
            task_vm_row($this->group, $this->host, $this->vmNode, $state);
        }
        $workspace = task_vm_instance($this->project, $this->{$node}, 'task-'.$this->group->id, $owned ? $this->group->id : null);

        expect(fn () => TaskVmWorkspaceGuard::assertWorkspace($this->group, $workspace))
            ->toThrow(TaskVmException::class, "Task group [{$this->group->id}] has no workspace on its ready task VM.");
    })->with([
        'no task VM' => [null, 'vmNode', true],
        'still provisioning' => [TaskVmState::Provisioning, 'vmNode', true],
        'failed' => [TaskVmState::Failed, 'vmNode', true],
        'shared Node' => [TaskVmState::Ready, 'shared', true],
        'named like the workspace without an owner' => [TaskVmState::Ready, 'vmNode', false],
    ]);
});
