<?php

declare(strict_types=1);

use App\Services\Nodes\LocalAgentAclEnsure;
use Symfony\Component\Process\Process;

it('treats optional directory ACL failure as non-fatal skipped metadata', function (): void {
    $calls = [];
    $directories = [
        '/home/orbit' => true,
        '/home/orbit/.config' => true,
        '/home/orbit/.config/orbit' => true,
        '/home/orbit/.local' => true,
        '/home/orbit/.local/bin' => true,
        '/home/orbit/orbit' => true,
        '/home/orbit/orbit/bin' => true,
    ];
    $paths = [
        '/home/orbit/.local/bin/orbit' => true,
        '/home/orbit/.local/bin/orbit-agent' => false,
        '/home/orbit/.config/orbit/config.json' => true,
        '/home/orbit/.config/orbit/install.json' => true,
    ];

    $ensure = new LocalAgentAclEnsure(
        directoryExists: static fn (string $path): bool => $directories[$path] ?? false,
        pathExists: static fn (string $path): bool => $paths[$path] ?? false,
        runner: function (array $command) use (&$calls): Process {
            $calls[] = $command;
            $line = implode(' ', $command);

            if ($line === 'setfacl --version') {
                return process_with_exit_code(0);
            }

            // Present optional checkout paths cannot accept ACL (virtiofs-style).
            if (
                $line === 'sudo setfacl -m u:agent:--x /home/orbit/orbit'
                || $line === 'sudo setfacl -m u:agent:--x /home/orbit/orbit/bin'
            ) {
                return process_with_exit_code(1);
            }

            return process_with_exit_code(0);
        },
    );

    $result = $ensure->ensure();

    expect($result['directory_acl_exit_code'] ?? null)
        ->toBe(0)
        ->and($result['binary_acl_exit_code'] ?? null)
        ->toBe(0)
        ->and($result['optional_directory_paths_applied'] ?? null)
        ->toBeEmpty()
        ->and($result['optional_directory_paths_skipped'] ?? null)
        ->toBe([
            ['path' => '/home/orbit/orbit', 'reason' => 'acl_unsupported'],
            ['path' => '/home/orbit/orbit/bin', 'reason' => 'acl_unsupported'],
        ])
        ->and(collect($calls)->contains(
            static fn (array $command): bool => (
                implode(' ', $command) === 'sudo setfacl -m u:agent:r-x /home/orbit/.local/bin/orbit'
            ),
        ))
        ->toBeTrue();
});

it('fails closed when required installed directory ACL fails', function (): void {
    $ensure = new LocalAgentAclEnsure(
        directoryExists: static fn (string $path): bool => false,
        pathExists: static fn (string $path): bool => false,
        runner: function (array $command): Process {
            $line = implode(' ', $command);

            if ($line === 'setfacl --version') {
                return process_with_exit_code(0);
            }

            if (str_starts_with($line, 'sudo setfacl -m u:agent:--x /home/orbit ')) {
                return process_with_exit_code(1);
            }

            return process_with_exit_code(0);
        },
    );

    expect(fn () => $ensure->ensure())
        ->toThrow(RuntimeException::class, 'stage=directory_acl');
});

function process_with_exit_code(int $exitCode): Process
{
    return new class($exitCode) extends Process {
        public function __construct(
            private int $forcedExitCode,
        ) {
            parent::__construct(['true']);
        }

        public function run(?callable $callback = null, array $env = []): int
        {
            return $this->forcedExitCode;
        }

        public function isSuccessful(): bool
        {
            return $this->forcedExitCode === 0;
        }

        public function getExitCode(): ?int
        {
            return $this->forcedExitCode;
        }
    };
}
