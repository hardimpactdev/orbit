<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\TaskVms\TaskVmCredentialGuard;
use RuntimeException;

/** Carries the agent envelope through a managed host. Only candidate binaries use file push. */
final readonly class IncusAgentTransport implements AgentTransport
{
    public function __construct(private AgentTransport $host, private string $project, private string $vm) {}

    public function platform(): string
    {
        return 'linux';
    }

    public function binaryPath(): string
    {
        return SshAgentTransport::LinuxBinary;
    }

    public function run(array $arguments, ProtectedInput $envelope, float $timeout, ?\Closure $output = null): CommandResult
    {
        if ($envelope->carriesGitCredential()) {
            throw TaskVmCredentialGuard::refused();
        }
        $root = array_slice($arguments, 0, 2) === ['sudo', '-n'];
        // Incus supplies root directly. Name the fixed guest account for account-level supervision,
        // as sudo does on the SSH adapter; root installers can still run before cloud-init creates it.
        $identity = $root ? ['--user', '0', '--group', '0', '--env', 'SUDO_USER=orbit'] : [
            '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--env', 'LOGNAME=orbit',
        ];

        return $this->host->run([
            'sudo', '-n', '/usr/bin/incus', '--project', $this->project, 'exec', '--mode=non-interactive',
            ...$identity, '--', $this->vm, ...($root ? array_slice($arguments, 2) : ['/usr/sbin/runuser', '--user', 'orbit', '--', ...$arguments]),
        ], $envelope, $timeout, $output);
    }

    public function stage(string $localFile): string
    {
        $hostPath = $this->host->stage($localFile);
        $guestPath = '/var/tmp/orbit-agent-candidate-'.bin2hex(random_bytes(8));
        try {
            $result = $this->host->run([
                'sudo', '-n', '/usr/bin/incus', '--project', $this->project, 'file', 'push',
                '--mode', '0700', '--uid', '0', '--gid', '0', '--', $hostPath, $this->vm.$guestPath,
            ], ProtectedInput::fromString(''), 180.0);
            if (! $result->succeeded()) {
                throw new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, "The agent candidate could not be staged in guest [{$this->vm}]: ".trim($result->stderr), result: $result);
            }
        } finally {
            $removed = $this->host->run(['rm', '-f', '--', $hostPath], ProtectedInput::fromString(''), 30.0);
            if (! $removed->succeeded()) {
                throw new RuntimeException("The host candidate for guest [{$this->vm}] could not be removed.");
            }
        }

        return $guestPath;
    }
}
