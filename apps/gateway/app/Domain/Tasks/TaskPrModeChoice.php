<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;

/** The one resolution of a group's mode: its own, else its Project's, else the Gateway's, with where it came from. */
final readonly class TaskPrModeChoice
{
    public const string Group = 'group';

    public const string Project = 'project';

    public const string Gateway = 'gateway';

    private function __construct(public TaskPrMode $mode, public string $source) {}

    /** A group's own mode changes only until it has a pull request, while no publish of it runs, and before it ends. */
    public static function refuseLocked(Task $group): void
    {
        $publishing = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)
            ->where('target_id', $group->id)->whereNotIn('status', OperationStatus::terminalValues())->exists();
        if ($group->pr_url !== null || $publishing || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.pr_mode_locked', 'The mode changes only until the group has a pull request, while no publish of it runs, and before it ends.', 409);
        }
    }

    public static function of(Task $group): self
    {
        if ($group->pr_mode instanceof TaskPrMode) {
            return new self($group->pr_mode, self::Group);
        }
        $project = $group->project->task_pr_mode;

        return $project instanceof TaskPrMode ? new self($project, self::Project) : new self(TaskPrMode::gateway(), self::Gateway);
    }
}
