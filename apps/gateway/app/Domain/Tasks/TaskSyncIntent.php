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
 * mirrors, and the vault key of the read token. The run brings the default branch in through the copy's Node mirror. See
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
        $base = self::base($group, $copy)
            ?? throw new ResourceOperationException('tasks.sync_failed', 'A task copy syncs only from a GitHub repository with a valid default branch.', 422);

        return [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'project_id' => $group->project_id, 'node_id' => $instance->node_id,
            'workspace' => ['repository' => $base['repository'], 'default_branch' => $base['default_branch'], 'branch' => 'task-'.$group->id],
            'copy' => [...$copy->intent(), 'start' => $base['start'], 'mirror' => $base['mirror'], 'source_mirror' => $base['source_mirror']],
            'git_authorization' => $base['git_authorization'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
        ];
    }

    /**
     * What the copy's `base` phase reads: the Project's GitHub repository and default branch, the copy's start, its own Node mirror and
     * the default's, and the vault key of a new read token, which the Gateway mints as the run starts. A sync and the handoff check of
     * a copy both bring the default branch in this way (docs/reference/tasks.md#the-handoff-check-merges-main-first). Null when the
     * Project has no GitHub repository or no valid default branch.
     *
     * @return array{repository:string,default_branch:string,start:string,mirror:string,source_mirror:string,git_authorization:array{vault:string,key:string}}|null
     */
    public static function base(Task $group, TaskInstanceWorkspace $copy): ?array
    {
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $branch = $group->project->default_branch;
        if ($repository === null || ! is_string($branch) || ! GitBranchName::isValid($branch)) {
            return null;
        }
        $source = $copy->runtime->clone_source_instance_id === null ? null : Instance::query()->find($copy->runtime->clone_source_instance_id);

        return [
            'repository' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git', 'default_branch' => $branch,
            'start' => $copy->start(), 'mirror' => InstanceContainer::mirror($copy->instance),
            'source_mirror' => $source instanceof Instance ? InstanceContainer::mirror($source) : '',
            'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-sync-token-'.Str::uuid()],
        ];
    }
}
