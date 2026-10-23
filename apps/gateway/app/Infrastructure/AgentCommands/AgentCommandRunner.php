<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Models\Node;

/**
 * Runs one named `orbit-agent` command on a Node and returns its typed output (ADR 0225).
 *
 * The runner is the only way the Gateway runs Node management code. It sends the command name and typed
 * input, never program source. A Node that answers with another agent binary gets the pinned one installed,
 * and the command is sent once more.
 */
interface AgentCommandRunner
{
    /**
     * @param  array<string, mixed>  $input  A `GitReadEnvironment` value travels as its variables, on input marked as
     *                                       carrying a Git credential, so it never reaches a task VM.
     * @param  float|null  $deadlineSeconds  The time left for this call; the command's own bound applies too.
     * @return array<string, mixed>
     *
     * @throws AgentCommandRefused when the command returns a declared refusal.
     * @throws AgentRunnerFailure when no valid result arrives.
     */
    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array;

    /**
     * Installs the pinned agent on a Node, also after a recorded failed installation, and clears that record.
     * Returns whether the binary changed.
     *
     * @throws AgentCommandRefused when the installation is refused or fails.
     * @throws AgentRunnerFailure when no valid result arrives.
     */
    public function install(Node $node): bool;
}
