<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestToolchain;

it('preserves a complete finite program, arguments, environment, exit and cleanup', function (string $ending, int $exitCode, bool $protected): void {
    $home = sys_get_temp_dir().'/orbit-buffered-bash-'.bin2hex(random_bytes(8));
    mkdir($home, 0o700);
    $program = '# '.str_repeat('complete-program-', 131_073)."\n".<<<'BASH'
        source_bytes=$(wc -c < "${BASH_SOURCE[0]}")
        printf '%s\n' "$1" "$FIXTURE_SENTINEL" "$((source_bytes))"
        printf 'fixture-stderr\n' >&2
        trap 'printf "inner-exit\n"' EXIT
        BASH;
    $program .= "\n".$ending."\n";
    $input = $protected ? ProtectedInput::fromString($program) : null;
    $transport = new LocalShellSshExecutor(programDirectory: $home);
    $transport->environment['FIXTURE_SENTINEL'] = 'case environment';

    try {
        $result = $transport->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', 'argument with spaces'],
                input: $protected ? null : $program,
                protectedInput: $input,
            ),
        );
        expect(strlen($program))->toBeGreaterThan(2 * 1024 * 1024)
            ->and($result->exitCode)->toBe($exitCode)
            ->and($result->stdout)->toBe('argument with spaces'."\ncase environment\n".strlen($program)."\ninner-exit\n")
            ->and($result->stderr)->toBe("fixture-stderr\n")
            ->and(glob($home.'/.orbit-program.*'))->toBe([]);
        if ($input !== null) {
            expect(stream_get_contents($input->stream()))->toBe($program);
        }
    } finally {
        $input?->close();
        (new Filesystem)->deleteDirectory($home);
    }
})->with([
    'successful exit' => ['exit 0', 0],
    'failed exit' => ['exit 7', 7],
    'errexit' => ['false; printf must-not-run', 1],
])->with(['plain input' => false, 'protected input' => true]);

it('keeps a credential input marked and its original resource alive until execution finishes', function (): void {
    $original = ProtectedInput::gitCredential("printf credential-program\n");
    $transport = new LocalShellSshExecutor;
    $transport->transformInput = function (string $input) use ($original): string {
        expect($original->carriesGitCredential())->toBeTrue()
            ->and(stream_get_contents($original->stream()))->toBe("printf credential-program\n");

        return $input;
    };

    try {
        $result = $transport->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(['bash', '-seu'], protectedInput: $original),
        );
        expect($result->exitCode)->toBe(0)
            ->and($result->stdout)->toBe('credential-program');
        expect(stream_get_contents($original->stream()))->toBe("printf credential-program\n");
    } finally {
        $original->close();
    }
});

it('keeps non-program stdin byte exact for other command forms', function (array $arguments, bool $protected): void {
    $bytes = implode('', array_map(chr(...), range(0, 255)))."\0\n\n";
    $input = $protected ? ProtectedInput::fromString($bytes) : null;
    try {
        $result = (new LocalShellSshExecutor)->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand($arguments, input: $protected ? null : $bytes, protectedInput: $input),
        );

        expect($result->exitCode)->toBe(7)
            ->and($result->stdout)->toBe($bytes)
            ->and($result->stderr)->toBe('');
        if ($input !== null) {
            expect(stream_get_contents($input->stream()))->toBe($bytes);
        }
    } finally {
        $input?->close();
    }
})->with([
    'bash command data' => [['bash', '-c', 'cat; exit 7']],
    'bash command after stdin flags' => [['bash', '-seu', '-c', 'cat; exit 7']],
    'Python command data' => [['python3', '-c', 'import sys; sys.stdout.buffer.write(sys.stdin.buffer.read()); sys.exit(7)']],
])->with(['plain input' => false, 'protected input' => true]);

it('stages finite Bash source without starting shell staging programs', function (): void {
    $home = sys_get_temp_dir().'/orbit-buffered-bash-'.bin2hex(random_bytes(8));
    mkdir($home, 0o700);
    mkdir($home.'/bin', 0o700);
    symlink(TestToolchain::require('bash', 'install Bash'), $home.'/bin/bash');
    foreach (['mktemp', 'cat', 'rm'] as $name) {
        file_put_contents($home.'/bin/'.$name, "#!/bin/sh\nexit 99\n");
        chmod($home.'/bin/'.$name, 0o700);
    }
    $transport = new LocalShellSshExecutor(programDirectory: $home);
    $transport->environment['PATH'] = $home.'/bin';
    $program = <<<'BASH'
        [[ "$-" == *e* && "$-" == *u* ]]
        printf '%s\n' "$1" "$2" "$3" "$0"
        exit 7
        BASH;
    try {
        $result = $transport->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(['bash', '-seu', '--', 'argument with spaces', 'back\\slash', ''], $program),
        );
        expect($result->exitCode)->toBe(7)
            ->and($result->stdout)->toBe("argument with spaces\nback\\slash\n\nbash\n")
            ->and($result->stderr)->toBe('')
            ->and(glob($home.'/.orbit-program.*'))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('keeps no-argument source positional parameters empty and source permissions private', function (): void {
    $home = sys_get_temp_dir().'/orbit-buffered-bash-'.bin2hex(random_bytes(8));
    mkdir($home, 0o700);
    $transport = new LocalShellSshExecutor(programDirectory: $home);
    $transport->environment['FIXTURE_PHP'] = PHP_BINARY;
    try {
        $result = $transport->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(['bash', '-seu'], <<<'BASH'
                printf '%s\n' "$#" "$0"
                "$FIXTURE_PHP" -n -r 'printf("%o", fileperms($argv[1]) & 0777);' "${BASH_SOURCE[0]}"
                BASH),
        );
        expect($result->exitCode)->toBe(0)
            ->and($result->stdout)->toBe("0\nbash\n600")
            ->and($result->stderr)->toBe('')
            ->and(glob($home.'/.orbit-program.*'))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('refuses a missing program directory without moving a protected program elsewhere', function (): void {
    $home = sys_get_temp_dir().'/orbit-buffered-bash-'.bin2hex(random_bytes(8));
    $input = ProtectedInput::fromString('printf must-not-run');
    $transport = new LocalShellSshExecutor(programDirectory: $home.'/missing');
    try {
        expect(fn () => $transport->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(['bash', '-seu'], protectedInput: $input),
        ))->toThrow(RuntimeException::class, 'The finite Bash program directory is missing.');
        expect(stream_get_contents($input->stream()))->toBe('printf must-not-run')
            ->and(file_exists($home))->toBeFalse();
    } finally {
        $input->close();
    }
});

it('preserves caller ownership of protected input when fixture instrumentation throws', function (): void {
    $input = ProtectedInput::fromString('must-not-run');
    $transport = new LocalShellSshExecutor;
    $transport->transformInput = fn (): never => throw new RuntimeException('fixture transform failed');
    try {
        expect(fn () => $transport->execute(
            new SshConnection('fixture', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(['bash', '-seu'], protectedInput: $input),
        ))->toThrow(RuntimeException::class, 'fixture transform failed');
        expect(stream_get_contents($input->stream()))->toBe('must-not-run');
    } finally {
        $input->close();
    }
});
