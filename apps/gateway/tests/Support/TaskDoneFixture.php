<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GitHub\GitHubRepository;
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
 * exactly its passed handoff check, which recorded the given mode, and that turn sent the pull request text
 * (docs/reference/tasks.md#the-reviewers-pull-request-text). Tests then break one clause at a time.
 */
final class TaskDoneFixture
{
    public const string Commit = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const string Title = 'Export the board as CSV';

    public const string Body = 'Adds a CSV export of the board.';

    public function __construct(public Task $group, public Task $subtask, public TaskCheck $check, public Instance $instance, public AgentThread $reviewer, public Operation $turn) {}

    public static function make(?TaskPrMode $recorded = TaskPrMode::PrWhenDone, bool $text = true): self
    {
        // A second world in one test gets its own Node, address and Project.
        $n = Project::query()->where('slug', 'like', 'trial%')->count();
        $suffix = $n === 0 ? '' : '-'.$n;
        $address = '10.44.0.'.(71 + $n);
        $node = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'work'.$suffix, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
            'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        ]));
        $node->forceFill(['instance_bridge' => '10.'.(253 - $n).'.0.0/24'])->saveQuietly();
        $project = Project::query()->create(['name' => 'Trial'.$suffix, 'slug' => 'trial'.$suffix, 'repository_url' => 'https://github.com/example/trial'.$suffix.'.git', 'default_branch' => 'main', 'root' => 'public']);
        $listed = config('orbit.tasks.copy_publish_projects');
        config(['orbit.tasks.copy_publish_projects' => [...($n === 0 || ! is_array($listed) ? [] : $listed), $project->id]]);
        $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Add the export', 'brief' => 'Export the board as CSV.', 'status' => TaskGroupStatus::Running]);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/var/lib/incus/rootfs/srv/workspace',
            'status' => InstanceState::Active, 'branch' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'starting_commit' => str_repeat('a', 40)]);
        InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $instance->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.'.(253 - $n).'.0.5', 'seed_commit' => str_repeat('a', 40)]);
        $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
        $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Write the export', 'brief' => 'Write it.', 'status' => TaskStatus::Running]);
        $check = self::check($group, $subtask, self::Commit, $recorded);
        $reviewer = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $node->id, 'driver' => 't3',
            'runtime_key' => 'instance:'.$instance->id, 'external_id' => 'reviewer-thread', 'role' => 'reviewer', 'model' => 'claude', 'effort' => 'high']);
        $group->update(['reviewer_agent_thread_id' => $reviewer->id]);
        $turn = self::reviewerTurn($group, $subtask, $reviewer, self::Commit, now()->subMinutes(2));
        if ($text) {
            $check->update(['pr_title' => self::Title, 'pr_body' => self::Body, 'pr_text_operation_id' => $turn->id]);
        }
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

    /** A succeeded publish from a task copy that opened pull request `$number`: the record that outlives the copy. */
    public static function copyPublish(Task $group, int $number): Operation
    {
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $name = $repository === null ? 'example/trial' : $repository->owner.'/'.$repository->name;

        return Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
            'input' => ['check' => 1, 'commit' => self::Commit, 'repository' => $name, 'workspace' => ['branch' => 'task-'.$group->id]], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
            'result' => ['branch' => 'task-'.$group->id, 'commit' => self::Commit, 'pull_request_number' => $number, 'pull_request_url' => 'https://github.com/'.$name.'/pull/'.$number]]);
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
