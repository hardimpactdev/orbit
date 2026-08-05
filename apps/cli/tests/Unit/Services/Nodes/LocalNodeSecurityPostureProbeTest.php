<?php

declare(strict_types=1);

use App\Services\Nodes\LocalNodeSecurityPostureProbe;
use Symfony\Component\Process\Process;

it('accepts exact owner mode 0700 without ACL inspection', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_700 : false,
        runner: static function (array $command): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command[0] ?? null)->not->toBe('getfacl');

            return security_posture_process(exitCode: 1);
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeTrue();
});

it('accepts 0710 when the only named ACL is managed agent execute with mask --x', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_710 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: <<<'ACL'
                    # file: /home/orbit
                    # owner: orbit
                    # group: orbit
                    user::rwx
                    user:agent:--x
                    group::---
                    mask::--x
                    other::---
                    ACL,
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeTrue();
});

it('accepts managed agent execute ACL when getfacl annotates effective rights', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_710 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: "user::rwx\nuser:agent:--x\t#effective:--x\ngroup::---\nmask::--x\nother::---\n",
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeTrue();
});

it('rejects group execute 0710 without a managed agent ACL', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_710 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: "user::rwx\ngroup::--x\nmask::--x\nother::---\n",
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeFalse();
});

it('rejects agent read ACL even when base owner bits are rwx', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_750 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: "user::rwx\nuser:agent:r-x\ngroup::---\nmask::r-x\nother::---\n",
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeFalse();
});

it('rejects agent write ACL', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_730 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: "user::rwx\nuser:agent:-wx\ngroup::---\nmask::-wx\nother::---\n",
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeFalse();
});

it('rejects additional named user ACL entries', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_710 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: "user::rwx\nuser:agent:--x\nuser:alice:--x\ngroup::---\nmask::--x\nother::---\n",
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeFalse();
});

it('rejects named group ACL entries', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_710 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(
                exitCode: 0,
                output: "user::rwx\nuser:agent:--x\ngroup::---\ngroup:ops:--x\nmask::--x\nother::---\n",
            );
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeFalse();
});

it('rejects world or group read/write base modes', function (): void {
    $home = '/home/orbit';
    $runner = static function (array $command): Process {
        if (($command[0] ?? null) === 'id') {
            return security_posture_process(exitCode: 0, output: "1000\n");
        }

        return security_posture_process(exitCode: 1);
    };

    foreach ([0o040_755, 0o040_744, 0o040_750, 0o040_701, 0o040_711] as $mode) {
        $probe = new LocalNodeSecurityPostureProbe(
            directoryExists: static fn (string $path): bool => $path === $home,
            filePermissions: fn (string $path): int|false => $path === $home ? $mode : false,
            runner: $runner,
        );

        expect($probe->check('orbit')['home_perms'])->toBeFalse('mode '.decoct($mode & 0o777));
    }
});

it('rejects non-0700 modes when getfacl is unavailable', function (): void {
    $home = '/home/orbit';
    $probe = new LocalNodeSecurityPostureProbe(
        directoryExists: static fn (string $path): bool => $path === $home,
        filePermissions: static fn (string $path): int|false => $path === $home ? 0o040_710 : false,
        runner: static function (array $command) use ($home): Process {
            if (($command[0] ?? null) === 'id') {
                return security_posture_process(exitCode: 0, output: "1000\n");
            }

            expect($command)->toBe(['getfacl', '-p', $home]);

            return security_posture_process(exitCode: 127, errorOutput: 'getfacl: not found');
        },
    );

    expect($probe->check('orbit')['home_perms'])->toBeFalse();
});

function security_posture_process(
    int $exitCode,
    string $output = '',
    string $errorOutput = '',
): Process {
    return new class($exitCode, $output, $errorOutput) extends Process {
        public function __construct(
            private int $forcedExitCode,
            private string $forcedOutput,
            private string $forcedErrorOutput,
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

        public function getOutput(): string
        {
            return $this->forcedOutput;
        }

        public function getErrorOutput(): string
        {
            return $this->forcedErrorOutput;
        }

        public function getExitCode(): ?int
        {
            return $this->forcedExitCode;
        }
    };
}
