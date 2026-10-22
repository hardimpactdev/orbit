<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use RuntimeException;

/** A managed Node over SSH to its WireGuard address, as its enrolled account with its pinned host key. */
final readonly class SshAgentTransport implements AgentTransport
{
    public const string LinuxBinary = '/usr/local/bin/orbit-agent';

    /** SSH starts a command in the login home, so this is `$HOME/.orbit/bin/orbit-agent` on macOS. */
    public const string MacosBinary = '.orbit/bin/orbit-agent';

    public function __construct(
        private Node $node,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    #[\Override]
    public function platform(): string
    {
        return $this->node->platform === 'macos' ? 'macos' : 'linux';
    }

    #[\Override]
    public function binaryPath(): string
    {
        return $this->platform() === 'macos' ? self::MacosBinary : self::LinuxBinary;
    }

    #[\Override]
    public function run(array $arguments, ProtectedInput $envelope, float $timeout): CommandResult
    {
        return $this->ssh->execute($this->connection($timeout), new RemoteCommand($arguments, protectedInput: $envelope, timeout: $timeout));
    }

    #[\Override]
    public function stage(string $localFile): string
    {
        $directory = $this->platform() === 'macos' ? '.orbit' : '/var/tmp';
        $path = "{$directory}/orbit-agent-candidate-".bin2hex(random_bytes(8));
        $result = $this->ssh->execute($this->connection(180.0), new RemoteCommand(
            ['install', '-m', '0700', '/dev/stdin', $path],
            protectedInput: ProtectedInput::openFile($localFile),
            timeout: 180.0,
        ));
        if (! $result->succeeded()) {
            throw new RuntimeException("The agent candidate could not be staged on Node [{$this->node->name}]: ".trim($result->stderr));
        }

        return $path;
    }

    private function connection(float $timeout): SshConnection
    {
        $host = $this->node->wireguard_ip;
        if (! is_string($host) || $host === '') {
            throw new RuntimeException("Node [{$this->node->name}] has no WireGuard address.");
        }

        return new SshConnection(
            host: $host,
            user: $this->node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            commandTimeout: $timeout,
        );
    }
}
