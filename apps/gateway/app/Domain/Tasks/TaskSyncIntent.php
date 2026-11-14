<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;

/**
 * The intent of `task:sync`: the repository, the two branches to bring into the workspace, the workspace path and the SSH target
 * of the VM. A task copy's intent has `copy` instead of a workspace path and the VM's read token: the run brings the default branch
 * in through the copy's Node mirror. See docs/cli/task/sync.mmd.
 */
final readonly class TaskSyncIntent
{
    /** @return array<string,mixed> */
    public function capture(Task $group): array
    {
        $group->loadMissing('project', 'taskable');
        $copy = TaskInstanceWorkspace::forGroup($group);
        if ($copy !== null) {
            return $this->copy($group, $copy);
        }
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

    /**
     * A task copy: its guest target and start, its own Node mirror and the default's, from which a missing one is made, and the vault
     * key of the read token that the Gateway mints as the run starts, the same Git header as a task VM's. The run fetches over HTTPS,
     * so the repository is named by its GitHub URL. The default's mirror is needed only when the copy's own is missing: a copy
     * whose default is gone names none and still syncs from its own mirror.
     *
     * @return array<string,mixed>
     */
    private function copy(Task $group, TaskInstanceWorkspace $copy): array
    {
        $instance = $copy->instance;
        $instance->loadMissing('node');
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $base = $group->project->default_branch;
        if ($repository === null || ! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new ResourceOperationException('tasks.sync_failed', 'A task copy syncs only from a GitHub repository with a valid default branch.', 422);
        }
        $source = $copy->runtime->clone_source_instance_id === null ? null : Instance::query()->find($copy->runtime->clone_source_instance_id);
        $target = $copy->intent();

        return [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'project_id' => $group->project_id, 'node_id' => $instance->node_id,
            'workspace' => ['repository' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git', 'default_branch' => $base, 'branch' => 'task-'.$group->id],
            'copy' => [...$target, 'start' => $copy->start(), 'mirror' => InstanceContainer::mirror($instance),
                'source_mirror' => $source instanceof Instance ? InstanceContainer::mirror($source) : ''],
            'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-sync-token-'.Str::uuid()],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
        ];
    }
}
