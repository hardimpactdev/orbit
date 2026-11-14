<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/** The Pi models of the task VM path, which a task copy never reads. */
final readonly class TaskAgentDefaults
{
    public const string ImplementerModel = 'gpt-5.6-luna';

    public const string ReviewerModel = 'gpt-5.6-luna';

    /** The Pi model of a new task VM thread of the role: the group's, else `orbit.tasks.<role>_model`, else the default above. */
    public static function vmModel(Task $group, string $role): string
    {
        $configured = config('orbit.tasks.'.$role.'_model');

        return ($role === 'implementer' ? $group->implementer_model : $group->reviewer_model)
            ?? (is_string($configured) && $configured !== '' ? $configured : ($role === 'implementer' ? self::ImplementerModel : self::ReviewerModel));
    }
}
