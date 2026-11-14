<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * A task copy whose work is done (docs/reference/tasks.md#when-the-work-is-done): its one subtask completed after a reviewer turn on
 * exactly its passed handoff check, which recorded the given mode. Tests then break one clause at a time.
 */
final class TaskCopyDoneFixture
{
    public const string Commit = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function __construct(public Task $group, public Task $subtask, public TaskCheck $check, public Instance $instance, public AgentThread $reviewer, public Operation $turn) {}

    public static function make(?TaskPrMode $recorded = TaskPrMode::PrWhenDone): self
    {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'work', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.71', 'public_ssh_port' => 22,
            'wireguard_ip' => '10.44.0.71', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        ]));
        $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->saveQuietly();
        $project = Project::query()->create(['name' => 'Trial', 'slug' => 'trial', 'repository_url' => 'https://github.com/example/trial.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['orbit.tasks.copy_publish_projects' => [$project->id]]);
        $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Add the export', 'brief' => 'Export the board as CSV.', 'status' => TaskGroupStatus::Running]);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/var/lib/incus/rootfs/srv/workspace',
            'status' => InstanceState::Active, 'branch' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'starting_commit' => str_repeat('a', 40)]);
        InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $instance->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.5', 'seed_commit' => str_repeat('a', 40)]);
        $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
        $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Write the export', 'brief' => 'Write it.', 'status' => TaskStatus::Running]);
        $check = self::check($group, $subtask, self::Commit, $recorded);
        $reviewer = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $node->id, 'driver' => 't3',
            'runtime_key' => 'instance:'.$instance->id, 'external_id' => 'reviewer-thread', 'role' => 'reviewer', 'model' => 'claude', 'effort' => 'high']);
        $group->update(['reviewer_agent_thread_id' => $reviewer->id]);
        $turn = self::reviewerTurn($group, $subtask, $reviewer, self::Commit, now()->subMinutes(2));
        $subtask->update(['status' => TaskStatus::Completed]);

        return new self($group->refresh(), $subtask->refresh(), $check, $instance, $reviewer, $turn);
    }

    /** A passed, eligible handoff check of the subtask with its owning `tasks.check` Operation. */
    public static function check(Task $group, Task $subtask, string $commit, ?TaskPrMode $recorded = TaskPrMode::PrWhenDone, TaskCheckStatus $status = TaskCheckStatus::Passed): TaskCheck
    {
        $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => $status, 'pid' => 1, 'process_started' => 'x',
            'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => $commit, 'tree_after' => str_repeat('4', 40),
            'commit_sha' => $commit, 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now(), 'pr_mode' => $recorded]);
        Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
            'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
            'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $commit, 'head' => $commit, 'tree' => $check->tree_after, 'ci' => null]]);

        return $check;
    }

    /** A finished copy turn of the reviewer thread on the candidate. */
    public static function reviewerTurn(Task $group, Task $subtask, AgentThread $reviewer, string $candidate, mixed $finished, string $state = 'done'): Operation
    {
        return Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
            'input' => ['subtask' => $subtask->id, 'role' => 'reviewer', 'thread_id' => $reviewer->id, 'candidate' => $candidate],
            'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
            'result' => ['state' => $state, 'ended' => true], 'finished_at' => $finished]);
    }
}
