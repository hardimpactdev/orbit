<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one no-merge rule (docs/reference/tasks.md#no-merge): Orbit never merges the pull request of a group whose workspace is a task copy,
 * also after the group ended. A person merges it on GitHub. A pull request is such a one when a `tasks.publish` from a task copy, whose
 * intent holds `workspace`, opened it, or when its head is the `task-<id>` branch of a group that is or was a copy group, which every copy
 * publish pushes; both records outlive the copy, and the branch also marks a publish that ended before it recorded its number.
 *
 * Both match by the repository, not by the Project: two Projects can name one repository, and a merge of one of them must still see the
 * pull request that a copy of the other opened. The publish intent names the repository it pushed to, so a later change of a Project's
 * repository does not move its old pull requests. A pull request that a group of another Project on the same repository published, or
 * whose head is such a group's task branch, is that Project's: `project:pr-merge` refuses it with {@see self::OtherProjectCode}.
 */
final class TaskPullRequest
{
    public const string Code = 'tasks.copy_pr_no_merge';

    public const string OtherProjectCode = 'project.pr_of_other_project';

    /** Whether the group has a task copy now or ever published from one. */
    public static function group(Task $group): bool
    {
        return TaskInstanceWorkspace::forGroup($group) !== null || self::copyPublishes()->where('target_id', $group->id)->exists();
    }

    /** Whether a task copy's publish to this Project's repository, of a group of any Project, opened pull request `$number`. */
    public static function published(Project $project, int $number): bool
    {
        return self::publishers($project, $number, self::copyPublishes()) !== [];
    }

    /**
     * Whether the head branch `task-<id>` belongs to a task copy group on this Project's repository: a group of a Project that names it,
     * or a copy publish of any status that pushed that branch to it, also after its Project's repository changed.
     */
    public static function branch(Project $project, ?string $ref): bool
    {
        $group = self::branchGroup($project, $ref);
        if ($group instanceof Task && self::group($group)) {
            return true;
        }
        $repository = self::repository($project);
        if ($ref === null || $repository === null) {
            return false;
        }

        return self::copyPublishes()->where('input->branch', $ref)->pluck('input')
            ->contains(static fn (mixed $input): bool => is_array($input) && is_string($input['repository'] ?? null) && strcasecmp($input['repository'], $repository) === 0);
    }

    /** Whether Orbit must not merge this group's pull request: the group is a copy group, or a copy publish opened its watched one. */
    public static function copy(Task $group): bool
    {
        $group->loadMissing('project');

        return self::group($group) || (is_int($group->watched_pr_number) && self::published($group->project, $group->watched_pr_number));
    }

    public static function refuseBranch(Project $project, ?string $ref): void
    {
        if (self::branch($project, $ref)) {
            throw self::refusal();
        }
    }

    /** The task-path check: the group's own copy state, and its watched pull request as any Project's copy publish on its repository. */
    public static function refuseGroup(Task $group): void
    {
        if (self::copy($group)) {
            throw self::refusal();
        }
    }

    public static function refusePublished(Project $project, int $number): void
    {
        if (self::published($project, $number)) {
            throw self::refusal();
        }
    }

    /**
     * `project:pr-merge` merges a pull request of its own Project only: one that a group of another Project on the same repository published,
     * or whose head is the task branch of such a group, is refused.
     */
    public static function refuseOtherProject(Project $project, int $number, ?string $ref): void
    {
        $owners = self::publishers($project, $number, Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget));
        $branch = self::branchGroup($project, $ref);
        if ($branch instanceof Task) {
            $owners[] = $branch->project_id;
        }
        if (array_any($owners, static fn (int $owner): bool => $owner !== $project->id)) {
            throw new ResourceOperationException(self::OtherProjectCode, 'This pull request belongs to a task group of another Project on the same repository; that Project merges it, or a person on GitHub.', 409);
        }
    }

    public static function refusal(): ResourceOperationException
    {
        return new ResourceOperationException(self::Code, 'Orbit does not merge the pull request of a task copy; a person merges it on GitHub.', 409);
    }

    /**
     * The Projects of the groups whose succeeded publish to this Project's repository opened pull request `$number`.
     *
     * @param  Builder<Operation>  $publishes
     * @return list<int>
     */
    private static function publishers(Project $project, int $number, Builder $publishes): array
    {
        $repository = self::repository($project);
        if ($repository === null) {
            return [];
        }
        $owners = [];
        foreach ($publishes->where('status', OperationStatus::Succeeded->value)->where('result->pull_request_number', $number)->get(['target_id', 'input']) as $publish) {
            $named = $publish->input['repository'] ?? null;
            if (is_string($named) && strcasecmp($named, $repository) === 0) {
                $owner = Task::topLevel()->whereKey($publish->target_id)->value('project_id');
                if (is_int($owner)) {
                    $owners[] = $owner;
                }
            }
        }

        return $owners;
    }

    /** The group of the head branch `task-<id>` when its Project names the same repository as this one. */
    private static function branchGroup(Project $project, ?string $ref): ?Task
    {
        if ($ref === null || preg_match('/\Atask-([1-9][0-9]*)\z/D', $ref, $match) !== 1) {
            return null;
        }
        $group = Task::topLevel()->with('project')->find((int) $match[1]);
        if (! $group instanceof Task) {
            return null;
        }
        $own = self::repository($project);

        return $group->project_id === $project->id || ($own !== null && strcasecmp(self::repository($group->project) ?? '', $own) === 0) ? $group : null;
    }

    private static function repository(Project $project): ?string
    {
        $repository = GitHubRepository::fromOrigin($project->repositoryUrl());

        return $repository === null ? null : $repository->owner.'/'.$repository->name;
    }

    /** @return Builder<Operation> */
    private static function copyPublishes(): Builder
    {
        return Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->whereNotNull('input->workspace');
    }
}
