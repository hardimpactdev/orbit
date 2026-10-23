<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\SshAgentTransport;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use RuntimeException;

/**
 * The agent built from this checkout, for integration tests that run real commands against temporary directories.
 * Build it with `cargo build --release` in `apps/agent`; the privileged CI job does. A release binary is small, so the
 * self-check that hashes it on every command stays fast. A test runs it as the current user and
 * marks it supervised, so a mutation runs in the test process instead of a systemd service.
 */
final class LocalAgentBinary
{
    public static function path(): string
    {
        $path = base_path('../agent/target/release/orbit-agent');
        if (! is_executable($path)) {
            throw new RuntimeException("The agent binary [{$path}] is missing. Run `cargo build --release` in apps/agent first.");
        }

        return $path;
    }

    /** A delivery that pins the built binary for `linux-x86_64` Nodes. */
    public static function delivery(string $directory): AgentDelivery
    {
        $binary = self::path();
        if (! is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }
        $manifest = $directory.'/manifest.json';
        file_put_contents($manifest, json_encode([
            'commit' => str_repeat('0', 40),
            'version' => '0.0.0',
            'artifacts' => ['linux-x86_64' => ['sha256' => hash_file('sha256', $binary), 'path' => $binary]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new AgentDelivery($manifest, $directory.'/artifacts');
    }

    /** The real runner over an SSH stand-in that runs this binary, with a delivery under `$directory`. */
    public static function runner(SshExecutor $ssh, SshKeyProvider $keys, KnownHostsStore $knownHosts, string $directory): NativeAgentCommandRunner
    {
        $transports = new class($ssh, $keys, $knownHosts) extends AgentTransports
        {
            public function __construct(private readonly SshExecutor $ssh, private readonly SshKeyProvider $keys, private readonly KnownHostsStore $knownHosts) {}

            public function for(Node $node): AgentTransport
            {
                return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts);
            }
        };

        return new NativeAgentCommandRunner(app(AgentContract::class), self::delivery($directory), $transports);
    }

    /**
     * The local arguments of an agent command that the runner sent over SSH, or null for any other command. `sudo -n`
     * is dropped and the installed path becomes the built binary.
     *
     * @param  list<string>  $arguments
     * @return list<string>|null
     */
    public static function localArguments(array $arguments): ?array
    {
        if (array_slice($arguments, 0, 2) === ['sudo', '-n']) {
            $arguments = array_slice($arguments, 2);
        }
        if (count($arguments) !== 3 || $arguments[1] !== 'command' || ! str_ends_with($arguments[0], 'orbit-agent')) {
            return null;
        }

        return [self::path(), 'command', $arguments[2]];
    }

    /**
     * The environment of a local command: supervised, with a private lock directory under `$sandbox`, as the root
     * process that starts a mutation on a Node prepares one.
     *
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    public static function environment(string $sandbox, array $environment = []): array
    {
        $locks = $sandbox.'/agent-locks';
        if (! is_dir($locks)) {
            mkdir($locks, 0o700, true);
        }

        return [...$environment, 'ORBIT_AGENT_SUPERVISED' => '1', 'ORBIT_AGENT_LOCK_DIR' => $locks];
    }
}
