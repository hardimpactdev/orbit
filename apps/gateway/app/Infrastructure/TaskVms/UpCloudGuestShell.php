<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;

/**
 * Runs commands on an UpCloud server that Orbit created, over SSH as {@see TaskVmCloudInit::User} with the host key
 * that the Gateway pinned ({@see UpCloudGuest}). The login sets `HOME` and `USER`. Root commands run with `sudo -n`.
 */
final readonly class UpCloudGuestShell implements TaskVmGuestShell
{
    public function __construct(
        private UpCloudGuest $guest,
        private string $address,
        private string $hostKey,
    ) {}

    public function agentTarget(): AgentGuest
    {
        return AgentGuest::ssh($this->address, 'x86_64', $this->guest->connection($this->address, $this->hostKey));
    }

    public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult
    {
        return $this->run($cwd === null ? $command : ['env', '-C', $cwd, '--', ...$command], $input, $timeout);
    }

    public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        return $this->run(['sudo', '-n', ...$command], $input, $timeout);
    }

    /** @param  list<string>  $command */
    private function run(array $command, string|ProtectedInput|null $input, float $timeout): CommandResult
    {
        return $this->guest->run($this->address, $this->hostKey, new RemoteCommand(
            $command,
            is_string($input) ? $input : null,
            $input instanceof ProtectedInput ? $input : null,
            timeout: $timeout,
        ));
    }
}
