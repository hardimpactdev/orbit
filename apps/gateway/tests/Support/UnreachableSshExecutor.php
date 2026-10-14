<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;

/**
 * Fails every SSH command at once, as `ssh` does when the host does not answer. A test whose Node address
 * routes to a real network would otherwise wait for the connect timeout on each command.
 */
final class UnreachableSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;
        $command->protectedInput?->close();

        return new CommandResult(255, '', "ssh: connect to host {$connection->host} port {$connection->port}: Connection timed out\n", 0, false);
    }
}
