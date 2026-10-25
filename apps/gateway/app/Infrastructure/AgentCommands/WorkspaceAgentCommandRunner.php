<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Closure;

/** The same runner with the existing workspace lease and operation journal around every command. */
interface WorkspaceAgentCommandRunner
{
    /**
     * @param  Closure(RemoteCommand): CommandResult  $execute
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function runInWorkspace(Node $node, Closure $execute, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null): array;
}
