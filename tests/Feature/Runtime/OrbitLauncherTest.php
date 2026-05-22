<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function orbitLauncherTempPath(string $name = ''): string
{
    $path = sys_get_temp_dir().'/orbit-launcher-test-'.bin2hex(random_bytes(6));

    return $name === '' ? $path : "{$path}/{$name}";
}

function writeOrbitLauncherExecutable(string $path, string $contents): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
    chmod($path, 0755);
}

function runOrbitLauncher(array $arguments, string $workingDirectory, array $environment): Process
{
    $process = new Process(
        ['/bin/bash', base_path('bin/orbit'), ...$arguments],
        $workingDirectory,
        $environment,
    );

    $process->run();

    return $process;
}

afterEach(function (): void {
    if (isset($this->tempDirectory) && is_dir($this->tempDirectory)) {
        File::deleteDirectory($this->tempDirectory);
    }
});

it('installs the host launcher instead of an artisan symlink', function (): void {
    $installer = File::get(base_path('bin/install-orbit'));

    expect($installer)
        ->toContain('ln -sf "$TARGET_DIR/bin/orbit" "$LINK_PATH"')
        ->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"');
});

it('passes host cwd uid and gid into orbit-runtime', function (): void {
    $this->tempDirectory = orbitLauncherTempPath();
    $fakeBin = "{$this->tempDirectory}/bin";
    $hostCwd = "{$this->tempDirectory}/workspace/docs";
    $dockerLog = "{$this->tempDirectory}/docker.log";

    File::ensureDirectoryExists($hostCwd);

    writeOrbitLauncherExecutable("{$fakeBin}/id", <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-u" ]; then
    printf '501\n'
    exit 0
fi

if [ "${1:-}" = "-g" ]; then
    printf '20\n'
    exit 0
fi

exit 99
SH);

    writeOrbitLauncherExecutable("{$fakeBin}/docker", <<<'SH'
#!/bin/sh
if [ "${1:-}" = "container" ] && [ "${2:-}" = "inspect" ]; then
    printf 'true\n'
    exit 0
fi

if [ "${1:-}" = "exec" ]; then
    : "${ORBIT_TEST_DOCKER_LOG:?}"

    for argument do
        printf '%s\n' "$argument" >> "$ORBIT_TEST_DOCKER_LOG"
    done

    exit 0
fi

printf 'unexpected docker invocation: %s\n' "$*" >&2
exit 99
SH);

    $result = runOrbitLauncher(
        ['node:list', '--json'],
        $hostCwd,
        [
            'PATH' => $fakeBin,
            'ORBIT_TEST_DOCKER_LOG' => $dockerLog,
        ],
    );

    $dockerArguments = explode("\n", trim(File::get($dockerLog)));
    $expectedHostCwd = realpath($hostCwd);

    if ($expectedHostCwd === false) {
        throw new RuntimeException("Unable to resolve test path: {$hostCwd}");
    }

    expect($result->isSuccessful())->toBeTrue()
        ->and($dockerArguments)->toBe([
            'exec',
            '--env',
            "ORBIT_HOST_CWD={$expectedHostCwd}",
            '--env',
            'ORBIT_HOST_UID=501',
            '--env',
            'ORBIT_HOST_GID=20',
            'orbit-runtime',
            'orbit',
            'node:list',
            '--json',
        ]);
});

it('refuses when Docker is missing', function (): void {
    $this->tempDirectory = orbitLauncherTempPath();
    File::ensureDirectoryExists($this->tempDirectory);

    $result = runOrbitLauncher(
        ['node:list'],
        $this->tempDirectory,
        ['PATH' => "{$this->tempDirectory}/bin"],
    );

    expect($result->getExitCode())->toBe(127)
        ->and($result->getErrorOutput())->toContain('Docker is required to run Orbit.');
});

it('refuses when orbit-runtime is missing', function (): void {
    $this->tempDirectory = orbitLauncherTempPath();
    $fakeBin = "{$this->tempDirectory}/bin";
    $execLog = "{$this->tempDirectory}/docker-exec.log";

    File::ensureDirectoryExists($this->tempDirectory);

    writeOrbitLauncherExecutable("{$fakeBin}/docker", <<<SH
#!/bin/sh
if [ "\${1:-}" = "container" ] && [ "\${2:-}" = "inspect" ]; then
    exit 1
fi

if [ "\${1:-}" = "exec" ]; then
    printf 'called\n' > "{$execLog}"
    exit 0
fi

exit 99
SH);

    $result = runOrbitLauncher(
        ['node:list'],
        $this->tempDirectory,
        ['PATH' => $fakeBin],
    );

    expect($result->getExitCode())->toBe(69)
        ->and($result->getErrorOutput())->toContain('orbit-runtime is not running.')
        ->and(File::exists($execLog))->toBeFalse();
});

it('never falls back to host PHP when orbit-runtime is missing', function (): void {
    $this->tempDirectory = orbitLauncherTempPath();
    $fakeBin = "{$this->tempDirectory}/bin";
    $phpLog = "{$this->tempDirectory}/php.log";

    writeOrbitLauncherExecutable("{$fakeBin}/docker", <<<'SH'
#!/bin/sh
if [ "${1:-}" = "container" ] && [ "${2:-}" = "inspect" ]; then
    exit 1
fi

exit 99
SH);

    writeOrbitLauncherExecutable("{$fakeBin}/php", <<<SH
#!/bin/sh
printf 'host php called\n' > "{$phpLog}"
exit 0
SH);

    $result = runOrbitLauncher(
        ['--version'],
        $this->tempDirectory,
        ['PATH' => $fakeBin],
    );

    expect($result->isSuccessful())->toBeFalse()
        ->and(File::exists($phpLog))->toBeFalse();
});
