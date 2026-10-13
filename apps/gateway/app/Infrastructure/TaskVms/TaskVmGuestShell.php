<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;

/**
 * Runs commands inside one builder or smoke VM, as {@see TaskVmCloudInit::User} or as root. Each provider reaches the
 * guest its own way: Incus with `incus exec` on the host ({@see IncusGuestShell}), UpCloud over SSH with the pinned
 * host key ({@see UpCloudGuestShell}). A command as the user runs from its home, or `$cwd`, with `HOME` and `USER` set.
 * The result is returned as is: the caller decides what a failure means.
 */
interface TaskVmGuestShell
{
    /**
     * @param  list<string>  $command
     * @param  string|ProtectedInput|null  $input  stdin; a file goes as `ProtectedInput`, so it is streamed
     */
    public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult;

    /**
     * @param  list<string>  $command
     * @param  string|ProtectedInput|null  $input  stdin; a file goes as `ProtectedInput`, so it is streamed
     */
    public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult;
}
