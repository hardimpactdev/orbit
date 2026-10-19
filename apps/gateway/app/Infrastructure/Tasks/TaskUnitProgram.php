<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use RuntimeException;

/** Embeds the shared unit-state proof in each self-contained remote task program. */
final class TaskUnitProgram
{
    public static function read(string $resource): string
    {
        $program = file_get_contents(resource_path($resource));
        $library = file_get_contents(resource_path('tasks/unit-state.py'));
        if (! is_string($program) || $program === '' || ! is_string($library) || $library === ''
            || substr_count($program, '# ORBIT_TASK_UNIT_STATE') !== 1) {
            throw new RuntimeException('The task unit supervision program is unavailable.');
        }

        return str_replace('# ORBIT_TASK_UNIT_STATE', $library, $program);
    }
}
