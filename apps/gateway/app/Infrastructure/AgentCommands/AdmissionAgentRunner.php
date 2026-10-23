<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;

/**
 * Runs agent commands on a Node that is still being admitted, over its pinned bootstrap SSH connection, until its
 * WireGuard address answers (ADR 0225). Everything else reaches a Node through `AgentCommandRunner`.
 */
interface AdmissionAgentRunner
{
    /**
     * Installs the pinned agent over the bootstrap connection. Returns whether the binary changed.
     *
     * @throws AgentCommandRefused when the installation is refused or fails.
     * @throws AgentRunnerFailure when no valid result arrives.
     */
    public function installOver(Node $node, SshConnection $connection): bool;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws AgentCommandRefused when the command returns a declared refusal.
     * @throws AgentRunnerFailure when no valid result arrives.
     */
    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array;
}
