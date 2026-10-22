<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;

/**
 * Carries one agent call to a Node. Every adapter carries the same argument vector, envelope, and result.
 * It may stage only a pinned candidate binary outside a named command; it has no general file-write API.
 */
interface AgentTransport
{
    /** `linux` or `macos`. */
    public function platform(): string;

    /** The absolute path of the installed agent on Linux, or the path below the login home on macOS. */
    public function binaryPath(): string;

    /** @param  non-empty-list<string>  $arguments */
    public function run(array $arguments, ProtectedInput $envelope, float $timeout): CommandResult;

    /** Copies a local, verified candidate to a new private path on the Node and returns that path. */
    public function stage(string $localFile): string;
}
