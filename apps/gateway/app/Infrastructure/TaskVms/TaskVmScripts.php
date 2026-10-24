<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;

/**
 * Reads the shell scripts in `resources/task-vms`, which Orbit runs on task VM hosts and in builder and smoke VMs. Each
 * file says who runs it and what its arguments are. Most go as the script of `bash -ceu <script> -- <arguments>`, so
 * only validated arguments follow it; some go on stdin.
 */
final class TaskVmScripts
{
    /** The path of `$name`, such as `apt-update.sh`, in `resources/task-vms`. */
    public static function path(string $name): string
    {
        return resource_path('task-vms/'.$name);
    }

    /**
     * The exact content of `$name`.
     *
     * @throws TaskVmException `task_vm.setup_unavailable` when the file is missing or unreadable
     */
    public static function read(string $name): string
    {
        $path = self::path($name);
        $script = is_file($path) ? file_get_contents($path) : false;

        return is_string($script) && $script !== ''
            ? $script
            : throw new TaskVmException('task_vm.setup_unavailable', "The task VM script [{$name}] is missing.");
    }

    /**
     * The argv that runs `$name` with Bash, exits on the first failed command or unset variable, and passes `$arguments`
     * as `$1`, `$2`, and so on.
     *
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public static function bash(string $name, array $arguments = []): array
    {
        return ['bash', '-ceu', self::read($name), '--', ...$arguments];
    }
}
