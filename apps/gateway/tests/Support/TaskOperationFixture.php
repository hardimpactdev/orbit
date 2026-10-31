<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\TaskVms\TaskVmProvider;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

/** Current Task operation and workspace fixtures, independent of the retired PHP scheduler. */
final class TaskOperationFixture
{
    public static function bindDefaults(): void
    {
        test_bind_snapshot_driver();
        app()->instance(SshExecutor::class, new UnreachableSshExecutor);
        app()->instance(TaskVmProvider::class, new RecordingTaskVmProvider);
        app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
        app()->instance(TaskWorkspaceStateReader::class, new NullTaskWorkspaceStateReader);
    }

    public static function create(): Task
    {
        $project = Project::query()->create([
            'name' => 'tick-app', 'slug' => 'tick-app',
            'repository_url' => 'git@example.test:tick.git',
            'default_branch' => 'main', 'task_check' => 'composer check', 'root' => 'public',
        ]);
        $node = Node::query()->create([
            'name' => 'tick-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.212', 'wireguard_ip' => '10.44.0.212',
        ]);
        $group = Task::topLevel()->create([
            'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
            'project_id' => $project->id, 'title' => 'Tick routing', 'brief' => 'Observe, classify, and execute.',
            'orchestration' => TaskOrchestration::External, 'status' => TaskGroupStatus::Running,
        ]);
        $workspace = Instance::query()->create([
            'task_workspace_owner_id' => $group->id, 'project_id' => $project->id, 'node_id' => $node->id,
            'name' => 'task-21', 'checkout_path' => '/srv/orbit/apps/tick-app/task-21',
            'starting_commit' => str_repeat('a', 40), 'branch' => 'task-21', 'status' => 'source_resolved',
        ]);
        $group->taskable()->associate($workspace);
        $group->save();
        Task::query()->create([
            'parent_id' => $group->id, 'position' => 1, 'title' => 'Models',
            'brief' => 'Store the records.', 'status' => TaskStatus::Running, 'started_at' => now(),
        ]);
        test_link_agent_threads($group);

        return test_vm_group($group);
    }
}
