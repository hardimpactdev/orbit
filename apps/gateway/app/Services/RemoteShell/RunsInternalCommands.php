<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\NodeCommandTransport\NodeTransportPreference;

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
     *     transport?: NodeTransportPreference|string,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     *     ssh_bootstrap_binary?: array{url: string, sha256: string},
     *     ssh_bootstrap_input_file?: array{path: string, sha256: string},
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
