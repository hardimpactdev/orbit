<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;

/**
 * The intent of `tasks:sync`: the repository, the two branches to bring into the workspace, the workspace path and the SSH target
 * of the VM. See docs/cli/tasks/sync.mmd.
 */
final readonly class TaskSyncIntent
{
    /** @return array<string,mixed> */
    public function capture(Task $group): array
    {
        $group->loadMissing('project', 'taskable');
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $instance->loadMissing('node');

        return [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'project_id' => $group->project_id, 'node_id' => $instance->node_id,
            'checkout_path' => $instance->checkout_path,
            // The Project repository, and the vault key that the Gateway writes the Git header to as it starts the run.
            'workspace' => ['repository' => $group->project->repository_url, 'default_branch' => $group->project->default_branch, 'branch' => 'task-'.$group->id],
            'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-sync-token-'.Str::uuid()],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
        ];
    }
}
