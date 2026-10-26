<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Nodes\MachineArchitecture;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use RuntimeException;

/**
 * A managed Node over SSH to its WireGuard address, as its enrolled account with its pinned host key, or over the
 * given connection, such as the bootstrap connection of a Node that is still being admitted.
 */
final readonly class SshAgentTransport implements AgentTransport
{
    public const string LinuxBinary = '/usr/local/bin/orbit-agent';

    /** SSH starts a command in the login home, so this is `$HOME/.orbit/bin/orbit-agent` on macOS. */
    public const string MacosBinary = '.orbit/bin/orbit-agent';

    public function __construct(
        private Node|AgentGuest $node,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ?SshConnection $over = null,
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
    public function run(array $arguments, ProtectedInput $envelope, float $timeout, ?\Closure $output = null): CommandResult
    {
        if ($this->connection($timeout)->user === 'root' && array_slice($arguments, 0, 2) === ['sudo', '-n']) {
            $arguments = array_slice($arguments, 2);
        }

        return $this->ssh->execute($this->connection($timeout), new RemoteCommand($arguments, protectedInput: $envelope, timeout: $timeout, maxOutputBytes: $output === null ? self::ResultBytes : 0, output: $output));
    }

    /** A fixed probe used only to select the first candidate binary during admission. */
    public function architecture(): string
    {
        $result = $this->ssh->execute($this->connection(10.0), new RemoteCommand(['uname', '-sm'], timeout: 10.0));
        $parts = preg_split('/\s+/', trim($result->stdout)) ?: [];
        $architecture = count($parts) === 2 ? $parts[1] : '';
        if ($result->succeeded() && count($parts) === 2 && $parts[0] !== ($this->platform() === 'macos' ? 'Darwin' : 'Linux')) {
            throw new AgentCommandRefused('agent.install', 'agent.platform_unsupported', 'The staging host does not match the requested platform.');
        }
        if (! $result->succeeded() || ! MachineArchitecture::isValid($architecture)) {
            throw new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, 'Could not select the agent architecture.', result: $result);
        }

        return $architecture;
    }

    #[\Override]
    public function stage(string $localFile, float $timeoutSeconds): string
    {
        $stageBy = microtime(true) + $timeoutSeconds;
        $left = static fn (float $limit): float => max(1.0, min($limit, $stageBy - microtime(true)));
        $directory = $this->platform() === 'macos' ? '/tmp' : '/var/tmp';
        $path = "{$directory}/orbit-agent-candidate-".bin2hex(random_bytes(8));
        $arguments = ['install', '-m', '0700', '/dev/stdin', $path];
        if ($this->platform() === 'macos') {
            // BSD install refuses a pipe as its source. Prepare the private file, then stream only candidate bytes.
            $prepared = $this->ssh->execute($this->connection($left(10.0)), new RemoteCommand(
                ['/usr/bin/install', '-m', '0700', '/dev/null', $path], timeout: $left(10.0),
            ));
            if (! $prepared->succeeded()) {
                throw new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, 'The Mac agent candidate could not be prepared.', result: $prepared);
            }
            $arguments = ['/bin/dd', 'of='.$path, 'bs=65536'];
        }
        $result = $this->ssh->execute($this->connection($left(180.0)), new RemoteCommand(
            $arguments,
            protectedInput: ProtectedInput::openFile($localFile),
            timeout: $left(180.0),
        ));
        if (! $result->succeeded()) {
            throw new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, "The agent candidate could not be staged on Node [{$this->node->name}]: ".trim($result->stderr), result: $result);
        }

        return $path;
    }

    private function connection(float $timeout): SshConnection
    {
        if ($this->over !== null) {
            return new SshConnection(
                host: $this->over->host,
                user: $this->over->user,
                port: $this->over->port,
                identityFile: $this->over->identityFile,
                knownHostsFile: $this->over->knownHostsFile,
                connectTimeout: $this->over->connectTimeout,
                commandTimeout: $timeout,
                shareConnection: $this->over->shareConnection,
                proxyJump: $this->over->proxyJump,
                hostIdentity: $this->over->hostIdentity,
            );
        }

        if (! $this->node instanceof Node) {
            throw new RuntimeException('A guest requires its pinned SSH connection.');
        }
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
