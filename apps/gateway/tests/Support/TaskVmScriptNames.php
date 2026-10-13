<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\TaskVms\TaskVmScripts;

/** Names the `resources/task-vms` script whose content a fake host received, so fakes match scripts by name. */
final class TaskVmScriptNames
{
    /** The file name of the script with exactly the content `$script`, such as `peer-send.sh`, or null for none. */
    public static function of(string $script): ?string
    {
        foreach (glob(TaskVmScripts::path('*.sh')) ?: [] as $path) {
            if (file_get_contents($path) === $script) {
                return basename($path);
            }
        }

        return null;
    }

    /**
     * The script that `$argv` runs as `bash -ceu <script> -- <arguments>`, wherever that sits in the argv, such as after
     * an `incus exec` envelope: its file name and its arguments. Null when `$argv` runs no such script.
     *
     * @param  list<string>  $argv
     * @return array{string, list<string>}|null
     */
    public static function call(array $argv): ?array
    {
        foreach ($argv as $at => $argument) {
            if ($argument === '-ceu' && ($argv[$at - 1] ?? null) === 'bash' && ($argv[$at + 2] ?? null) === '--') {
                $name = self::of($argv[$at + 1] ?? '');

                return $name === null ? null : [$name, array_values(array_slice($argv, $at + 3))];
            }
        }

        return null;
    }
}
