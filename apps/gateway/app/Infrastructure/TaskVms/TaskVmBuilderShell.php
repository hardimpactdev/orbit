<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;

/**
 * Runs a Node program in a workspace template builder VM as `orbit`, through `incus exec` on its host, the way
 * the base image build runs enrollment programs. Code that converges a Node over SSH, such as the PHP package
 * program, then runs unchanged in a VM that never enrolls. It ignores the connection: the builder has no address
 * of its own, and the host is the trusted path to it.
 */
final readonly class TaskVmBuilderShell implements SshExecutor
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmHost $host,
        private Node $hostNode,
        private string $builder,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return $this->incus->run($this->host, $this->hostNode, [
            'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit',
            '--', $this->builder, 'runuser', '-u', 'orbit', '--', ...$command->arguments,
        ], $command->protectedInput ?? $command->input, $command->timeout ?? $connection->commandTimeout);
    }
}
