<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

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
 */
final class TaskCopyPullRequest
{
    public const string Code = 'tasks.copy_pr_no_merge';

    /** Whether the group has a task copy now or ever published from one. */
    public static function group(Task $group): bool
    {
        return TaskInstanceWorkspace::forGroup($group) !== null || self::copyPublishes()->where('target_id', $group->id)->exists();
    }

    /** Whether a task copy's publish of a group of this Project opened pull request `$number`. */
    public static function published(Project $project, int $number): bool
    {
        return self::copyPublishes()->where('status', OperationStatus::Succeeded->value)->where('result->pull_request_number', $number)
            ->whereIn('target_id', Task::topLevel()->where('project_id', $project->id)->select('id'))->exists();
    }

    /** Whether the head branch `task-<id>` belongs to a group of this Project that is or was a task copy group. */
    public static function branch(Project $project, ?string $ref): bool
    {
        if ($ref === null || preg_match('/\Atask-([1-9][0-9]*)\z/D', $ref, $match) !== 1) {
            return false;
        }
        $group = Task::topLevel()->where('project_id', $project->id)->find((int) $match[1]);

        return $group instanceof Task && self::group($group);
    }

    public static function refuseBranch(Project $project, ?string $ref): void
    {
        if (self::branch($project, $ref)) {
            throw self::refusal();
        }
    }

    public static function refuseGroup(Task $group): void
    {
        if (self::group($group)) {
            throw self::refusal();
        }
    }

    public static function refusePublished(Project $project, int $number): void
    {
        if (self::published($project, $number)) {
            throw self::refusal();
        }
    }

    public static function refusal(): ResourceOperationException
    {
        return new ResourceOperationException(self::Code, 'Orbit does not merge the pull request of a task copy; a person merges it on GitHub.', 409);
    }

    /** @return Builder<Operation> */
    private static function copyPublishes(): Builder
    {
        return Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->whereNotNull('input->workspace');
    }
}
