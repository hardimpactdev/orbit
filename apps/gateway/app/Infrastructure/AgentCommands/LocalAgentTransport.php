<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use RuntimeException;

/**
 * The Gateway's own Node, as a local process: the Gateway does not SSH to itself. A Gateway worker can have a
 * read-only view of the system, so a candidate is staged under `ORBIT_HOME`. Mutations still change the Node,
 * because each one runs in its own transient systemd service outside the worker's view.
 */
final readonly class LocalAgentTransport implements AgentTransport
{
    public function __construct(
        private ProcessRunner $processes,
        private string $orbitHome,
    ) {}

    #[\Override]
    public function platform(): string
    {
        return 'linux';
    }

    #[\Override]
    public function binaryPath(): string
    {
        return SshAgentTransport::LinuxBinary;
    }

    #[\Override]
    public function run(array $arguments, ProtectedInput $envelope, float $timeout, ?\Closure $output = null): CommandResult
    {
        return $this->processes->run(new ProcessInvocation($arguments, timeout: $timeout, protectedInput: $envelope, maxOutputBytes: $output === null ? self::ResultBytes : 0, output: $output));
    }

    #[\Override]
    public function stage(string $localFile): string
    {
        $directory = "{$this->orbitHome}/agent-candidates";
        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The agent candidate directory cannot be created.');
        }
        $path = "{$directory}/orbit-agent-candidate-".bin2hex(random_bytes(8));
        if (! @copy($localFile, $path) || ! chmod($path, 0o700)) {
            @unlink($path);

            throw new RuntimeException('The agent candidate could not be staged on the Gateway.');
        }

        return $path;
    }
}
