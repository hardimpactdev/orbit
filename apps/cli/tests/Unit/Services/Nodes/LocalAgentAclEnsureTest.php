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

it('completes config ACL when install.json is absent but config.json remains required', function (): void {
    $calls = [];
    $paths = [
        '/home/orbit/.local/bin/orbit' => true,
        '/home/orbit/.local/bin/orbit-agent' => false,
        '/home/orbit/.config/orbit/config.json' => true,
        '/home/orbit/.config/orbit/install.json' => false,
    ];

    $ensure = new LocalAgentAclEnsure(
        directoryExists: static fn (string $path): bool => true,
        pathExists: static fn (string $path): bool => $paths[$path] ?? false,
        runner: function (array $command) use (&$calls): Process {
            $calls[] = $command;
            $line = implode(' ', $command);

            if ($line === 'setfacl --version') {
                return process_with_exit_code(0);
            }

            return process_with_exit_code(0);
        },
    );

    $result = $ensure->ensure();

    expect($result['config_acl_exit_code'] ?? null)
        ->toBe(0)
        ->and($result['optional_config_paths_skipped'] ?? null)
        ->toBe([
            ['path' => '/home/orbit/.config/orbit/install.json', 'reason' => 'absent'],
        ])
        ->and($result['optional_config_paths_applied'] ?? null)
        ->toBeEmpty()
        ->and(collect($calls)->contains(
            static fn (array $command): bool => (
                implode(' ', $command) === 'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/config.json'
            ),
        ))
        ->toBeTrue()
        ->and(collect($calls)->contains(
            static fn (array $command): bool => str_contains(
                implode(' ', $command),
                '/home/orbit/.config/orbit/install.json',
            ),
        ))
        ->toBeFalse();
});

it('applies optional install.json ACL when metadata is present', function (): void {
    $calls = [];
    $paths = [
        '/home/orbit/.local/bin/orbit' => true,
        '/home/orbit/.local/bin/orbit-agent' => false,
        '/home/orbit/.config/orbit/config.json' => true,
        '/home/orbit/.config/orbit/install.json' => true,
    ];

    $ensure = new LocalAgentAclEnsure(
        directoryExists: static fn (string $path): bool => true,
        pathExists: static fn (string $path): bool => $paths[$path] ?? false,
        runner: function (array $command) use (&$calls): Process {
            $calls[] = $command;

            return process_with_exit_code(0);
        },
    );

    $result = $ensure->ensure();

    expect($result['optional_config_paths_applied'] ?? null)
        ->toBe(['/home/orbit/.config/orbit/install.json'])
        ->and(collect($calls)->contains(
            static fn (array $command): bool => (
                implode(' ', $command) === 'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/install.json'
            ),
        ))
        ->toBeTrue();
});

it('fails closed when required config.json ACL fails', function (): void {
    $ensure = new LocalAgentAclEnsure(
        directoryExists: static fn (string $path): bool => true,
        pathExists: static fn (string $path): bool => (
            $path === '/home/orbit/.config/orbit/config.json'
            || $path === '/home/orbit/.local/bin/orbit'
        ),
        runner: function (array $command): Process {
            $line = implode(' ', $command);

            if ($line === 'setfacl --version') {
                return process_with_exit_code(0);
            }

            if ($line === 'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/config.json') {
                return process_with_exit_code(1);
            }

            return process_with_exit_code(0);
        },
    );

    expect(fn () => $ensure->ensure())
        ->toThrow(RuntimeException::class, 'stage=config_acl');
});

it('fails closed when required config.json is missing before setfacl', function (): void {
    $setfaclConfigCalls = 0;

    $ensure = new LocalAgentAclEnsure(
        directoryExists: static fn (string $path): bool => true,
        pathExists: static fn (string $path): bool => $path === '/home/orbit/.local/bin/orbit',
        runner: function (array $command) use (&$setfaclConfigCalls): Process {
            $line = implode(' ', $command);

            if ($line === 'setfacl --version') {
                return process_with_exit_code(0);
            }

            if ($line === 'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/config.json') {
                $setfaclConfigCalls++;

                return process_with_exit_code(0);
            }

            return process_with_exit_code(0);
        },
    );

    expect(fn () => $ensure->ensure())
        ->toThrow(
            RuntimeException::class,
            'stage=config_acl). Required path is missing: /home/orbit/.config/orbit/config.json',
        )
        ->and($setfaclConfigCalls)
        ->toBe(0);
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
