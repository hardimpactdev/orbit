<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Infrastructure\Ssh\RemoteCommand;
use InvalidArgumentException;
use RuntimeException;

/** The fixed CI role modes of the existing Incus host program. */
final readonly class CiRunnerHostProgram
{
    public static function command(string $action): RemoteCommand
    {
        if (! in_array($action, ['converge', 'inspect', 'remove', 'purge'], true)) {
            throw new InvalidArgumentException('Invalid CI host action.');
        }
        $source = file_get_contents(resource_path('task-vms/incus-host.sh'));
        if ($source === false) {
            throw new RuntimeException('The Incus host program is missing.');
        }

        return new RemoteCommand(['sudo', 'bash', '-seu', '--', '--ci-runner', $action], $source, timeout: 180.0);
    }
}
