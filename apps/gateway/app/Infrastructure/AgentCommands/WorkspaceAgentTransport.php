<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use Closure;

/** Retains the workspace's execution journal without duplicating command encoding or pin recovery. */
final readonly class WorkspaceAgentTransport implements AgentTransport
{
    /** @param  Closure(RemoteCommand): CommandResult  $execute */
    public function __construct(private AgentTransport $transport, private Closure $execute) {}

    public function platform(): string
    {
        return $this->transport->platform();
    }

    public function binaryPath(): string
    {
        return $this->transport->binaryPath();
    }

    public function run(array $arguments, ProtectedInput $envelope, float $timeout): CommandResult
    {
        return ($this->execute)(new RemoteCommand(
            arguments: $arguments,
            protectedInput: $envelope,
            maxOutputBytes: self::ResultBytes,
            timeout: $timeout,
        ));
    }

    public function stage(string $localFile): string
    {
        return $this->transport->stage($localFile);
    }
}
