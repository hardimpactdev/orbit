<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

/** What Orbit does once a task copy's work is done (docs/reference/tasks.md#task-group-modes). */
enum TaskPrMode: string
{
    case ReviewBeforePr = 'review_before_pr';
    case PrWhenDone = 'pr_when_done';

    /** The Gateway's default, `orbit.tasks.pr_mode`; any other value refuses rather than guessing a mode. */
    public static function gateway(): self
    {
        $value = config('orbit.tasks.pr_mode');

        return (is_string($value) ? self::tryFrom($value) : null)
            ?? throw new ResourceOperationException('tasks.pr_mode_setting_invalid', 'ORBIT_TASKS_PR_MODE must be review_before_pr or pr_when_done.', 503);
    }
}
