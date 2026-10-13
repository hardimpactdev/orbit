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
}
