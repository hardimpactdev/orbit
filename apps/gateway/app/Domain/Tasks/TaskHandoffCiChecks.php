<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/** The checked candidate commit message cannot contain a CI skip directive. */
final class TaskHandoffCiChecks
{
    private const string SkipDirectives = '/\[(?:skip ci|ci skip|no ci|skip actions|actions skip)\]|^[ \t]*skip-checks[ \t]*:[ \t]*true[ \t]*$/im';

    /** The subtask title and the implementer's summary, without a directive that would make GitHub skip CI. */
    public static function message(Task $task, string $summary = ''): string
    {
        return (string) preg_replace(self::SkipDirectives, '', $task->title."\n\n".$summary);
    }
}
