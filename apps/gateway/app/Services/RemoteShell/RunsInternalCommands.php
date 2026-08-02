<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;

interface RunsInternalCommands
{
    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult;
}
