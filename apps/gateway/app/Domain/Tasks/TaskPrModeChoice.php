<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/** The one resolution of a group's mode: its own, else its Project's, else the Gateway's, with where it came from. */
final readonly class TaskPrModeChoice
{
    public const string Group = 'group';

    public const string Project = 'project';

    public const string Gateway = 'gateway';

    private function __construct(public TaskPrMode $mode, public string $source) {}

    public static function of(Task $group): self
    {
        if ($group->pr_mode instanceof TaskPrMode) {
            return new self($group->pr_mode, self::Group);
        }
        $project = $group->project->task_pr_mode;

        return $project instanceof TaskPrMode ? new self($project, self::Project) : new self(TaskPrMode::gateway(), self::Gateway);
    }
}
