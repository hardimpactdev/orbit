<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;

/**
 * Runs a Node program in a builder VM as {@see TaskVmCloudInit::User} through its {@see TaskVmGuestShell}, the way the
 * base image build runs enrollment programs. Code that converges a Node over SSH, such as the PHP package program,
 * then runs unchanged in a VM that never enrolls. It ignores the connection but its timeout: the guest shell knows
 * how to reach the VM.
 */
final readonly class TaskVmBuilderShell implements SshExecutor
{
    public function __construct(private TaskVmGuestShell $guest) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return $this->guest->asUser($command->arguments, $command->protectedInput ?? $command->input, $command->timeout ?? $connection->commandTimeout);
    }
}
