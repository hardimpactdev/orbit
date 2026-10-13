<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;

/**
 * Runs each remote command in a local shell, like {@see LocalShellSshExecutor}, and records it. A command that
 * streams its output, such as a Git pack, gets its standard output once, at the end.
 */
final class StreamingLocalShellSshExecutor implements SshExecutor
{
    /** @var list<array{SshConnection, RemoteCommand}> */
    public array $sent = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->sent[] = [$connection, $command];
        $result = (new LocalShellSshExecutor)->execute($connection, $command);
        if ($command->output !== null && $result->stdout !== '') {
            ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, $result->stdout));
        }

        return $result;
    }
}
