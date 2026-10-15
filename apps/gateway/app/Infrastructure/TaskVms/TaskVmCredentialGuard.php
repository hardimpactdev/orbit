<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\TaskVm;

/**
 * Refuses an SSH command that carries a source Git credential when it would reach a task VM: a live task VM row with the
 * target's WireGuard or bridge address. Every SSH command of the Gateway passes here, so source Git
 * credentials stay on the Gateway. Composer setup uses a separate short-lived read-only token ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)).
 */
final readonly class TaskVmCredentialGuard implements SshExecutor
{
    public function __construct(private NativeSshExecutor $ssh) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($command->protectedInput?->carriesGitCredential() === true && self::isTaskVm($connection->host)) {
            throw self::refused();
        }

        return $this->ssh->execute($connection, $command);
    }

    public static function refused(): TaskVmException
    {
        return new TaskVmException('task_vm.git_credential_refused', 'A command that carries a GitHub credential cannot run on a task VM. The Gateway fetches and pushes for task VMs in its own mirror.');
    }

    private static function isTaskVm(string $host): bool
    {
        return TaskVm::query()->live()
            ->where(fn ($query) => $query->where('wireguard_ip', $host)->orWhere('address', $host))
            ->exists();
    }
}
