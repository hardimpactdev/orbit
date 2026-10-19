<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use RuntimeException;

/**
 * Embeds the shared unit-state proof in task monitors and recipe programs.
 * # Uses: prepends same-language libraries; topology needs Python inside a Bash heredoc.
 */
final class TaskUnitProgram
{
    public static function read(string $resource): string
    {
        $program = file_get_contents(resource_path($resource));
        if (! is_string($program) || $program === '') {
            throw new RuntimeException('The task unit supervision program is unavailable.');
        }

        if ($resource === 'tasks/check') {
            $policy = file_get_contents(resource_path('tasks/check-output.json'));
            if (! is_string($policy) || $policy === '' || substr_count($program, '__ORBIT_TASK_CHECK_OUTPUT_POLICY__') !== 1) {
                throw new RuntimeException('The task check output policy is unavailable.');
            }
            $program = str_replace('__ORBIT_TASK_CHECK_OUTPUT_POLICY__', trim($policy), $program);
        }

        return self::embed($program);
    }

    public static function embed(string $program): string
    {
        $library = file_get_contents(resource_path('tasks/unit-state.py'));
        if (! is_string($library) || $library === '' || substr_count($program, '# ORBIT_TASK_UNIT_STATE') !== 1) {
            throw new RuntimeException('The task unit supervision program is unavailable.');
        }

        return str_replace('# ORBIT_TASK_UNIT_STATE', $library, $program);
    }
}
