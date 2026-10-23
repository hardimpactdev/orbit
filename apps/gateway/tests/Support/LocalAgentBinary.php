<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentDelivery;
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

    /** The environment of a local command: supervised, with the given variables. @param array<string, string> $environment */
    public static function environment(array $environment = []): array
    {
        return [...$environment, 'ORBIT_AGENT_SUPERVISED' => '1'];
    }
}
