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
use Illuminate\Support\Str;

/**
 * The intent of `task:sync` in a task copy: the repository, the default branch and the task branch, the copy's guest target and
 * mirrors, what the base's content read counts as published, and the vault key of the read token. The run brings the default branch in through the copy's Node mirror. See
 * docs/cli/task/sync.mmd.
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
        // A sync runs only in the group's task copy; task VM workspaces are removed (docs/reference/old-paths.md).
        throw new ResourceOperationException('tasks.no_workspace', 'The group requires its task copy.', 409);
    }

    /**
     * A task copy: its guest target and start, its own Node mirror and the default's, from which a missing one is made, and the vault
     * key of the read token that the Gateway mints as the run starts, as a Git header. The run fetches over HTTPS,
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
                'source_mirror' => $source instanceof Instance ? InstanceContainer::mirror($source) : '',
                // The base's content read counts what these hold as published (docs/reference/tasks.md#published-work-of-a-copy).
                'published' => TaskCopyEnd::published($group), 'approved' => TaskFinalReview::approvedCommit($group) ?? ''],
            'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-sync-token-'.Str::uuid()],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
        ];
    }
}
