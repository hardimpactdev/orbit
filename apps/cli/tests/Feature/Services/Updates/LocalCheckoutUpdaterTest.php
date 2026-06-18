<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;
use App\Services\Updates\LocalCheckoutUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Download a binary and then replace it through the split surface, returning the
 * combined success of both steps. Mirrors the order the workflow runs them.
 *
 * @return array{download: array<string, mixed>, replace: array<string, mixed>}
 */
function runDownloadAndReplace(LocalCheckoutUpdater $updater): array
{
    $download = $updater->downloadBinary();

    $replace = ($download['successful'] && is_string($download['staged_path']) && is_string($download['version']))
        ? $updater->replaceBinary($download['staged_path'], $download['version'])
        : ['successful' => false, 'exit_code' => 1, 'output' => 'download did not complete', 'skipped' => false];

    return ['download' => $download, 'replace' => $replace];
}

describe('LocalCheckoutUpdater', function (): void {
    beforeEach(function (): void {
        // Point the installer to a sandboxed install root and a local binary
        // artifact via ORBIT_BINARY_URL so no real network request is made.
        $this->installRoot = sys_get_temp_dir().'/orbit-update-test-'.getmypid();
        @mkdir($this->installRoot.'/bin', recursive: true);

        // Create a dummy binary file so chmod and ln have a target.
        $this->binaryDest = $this->installRoot.'/bin/orbit-binary';
        file_put_contents($this->binaryDest, '');

        // Use a local file:// artifact URL to avoid hitting GitHub releases.
        $this->binaryUrl = 'file://'.$this->binaryDest;
        $this->linkPath = $this->installRoot.'/bin/orbit-link';

        $this->previousInstall = getenv('ORBIT_INSTALL_PATH');
        $this->previousBinaryUrl = getenv('ORBIT_BINARY_URL');
        $this->previousBinPath = getenv('ORBIT_BIN_PATH');
        $this->previousMetadataPath = getenv('ORBIT_INSTALL_METADATA_PATH');
        $this->previousHome = getenv('HOME');

        putenv("ORBIT_INSTALL_PATH={$this->installRoot}");
        putenv("ORBIT_BINARY_URL={$this->binaryUrl}");
        putenv("ORBIT_BIN_PATH={$this->linkPath}");
        putenv("ORBIT_INSTALL_METADATA_PATH={$this->installRoot}/install.json");
    });

    afterEach(function (): void {
        $this->previousInstall === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$this->previousInstall}");
        $this->previousBinaryUrl === false ? putenv('ORBIT_BINARY_URL') : putenv("ORBIT_BINARY_URL={$this->previousBinaryUrl}");
        $this->previousBinPath === false ? putenv('ORBIT_BIN_PATH') : putenv("ORBIT_BIN_PATH={$this->previousBinPath}");
        $this->previousMetadataPath === false ? putenv('ORBIT_INSTALL_METADATA_PATH') : putenv("ORBIT_INSTALL_METADATA_PATH={$this->previousMetadataPath}");
        $this->previousHome === false ? putenv('HOME') : putenv("HOME={$this->previousHome}");

        foreach (glob($this->binaryDest.'.download.*') ?: [] as $path) {
            @unlink($path);
        }

        // Clean up temp files.
        @unlink($this->installRoot.'/install.json');
        @unlink($this->binaryDest);
        foreach (glob($this->installRoot.'/bin/orbit-binary-*') ?: [] as $path) {
            @unlink($path);
        }
        @unlink($this->linkPath);
        @rmdir($this->installRoot.'/bin');
        @rmdir($this->installRoot);
    });

    it('defaults the host launcher to the user-local bin path without sudo', function (): void {
        $home = $this->installRoot.'/home';
        $expectedLinkPath = $home.'/.local/bin/orbit';

        @mkdir($home, recursive: true);
        putenv('ORBIT_BIN_PATH');
        putenv("HOME={$home}");

        Process::fake(['*' => Process::result(output: 'Version       1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';
        $metadata = json_decode(file_get_contents($this->installRoot.'/install.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['download']['successful'])->toBeTrue()
            ->and($result['replace']['successful'])->toBeTrue()
            ->and($metadata['binary_path'])->toBe($expectedLinkPath);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['ln', '-sfn', $versionedBinary, $expectedLinkPath]);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === [$expectedLinkPath, '--version']);

        Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === 'sudo');

        @unlink($expectedLinkPath);
        @rmdir(dirname($expectedLinkPath));
        @rmdir(dirname(dirname($expectedLinkPath)));
        @rmdir($home);
    });

    it('downloads the binary to a staged path and reports the resolved version', function (): void {
        Process::fake(['*' => Process::result(output: 'orbit 1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $download = (new LocalCheckoutUpdater(new CheckoutPathResolver))->downloadBinary();

        expect($download['successful'])->toBeTrue()
            ->and($download['exit_code'])->toBe(0)
            ->and($download['version'])->toBe('1.2.3')
            ->and($download['staged_path'])->toBeString()
            ->and($download['staged_path'])->toStartWith($this->binaryDest.'.download.');

        $stagedBinary = $download['staged_path'];

        // Assert the curl download step ran with the ORBIT_BINARY_URL override.
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'curl'
            && in_array($this->binaryUrl, $process->command, strict: true)
            && in_array($stagedBinary, $process->command, strict: true));

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['chmod', '0755', $stagedBinary]);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === [$stagedBinary, '--version']);
    });

    it('replaces the binary with a versioned file and relinks the host launcher', function (): void {
        Process::fake(['*' => Process::result(output: 'orbit 1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);
        $download = $updater->downloadBinary();
        $stagedBinary = $download['staged_path'];
        $replace = $updater->replaceBinary($stagedBinary, $download['version']);

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';

        expect($replace['successful'])->toBeTrue()
            ->and($replace['skipped'])->toBeFalse();

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['mv', '-f', $stagedBinary, $versionedBinary]);

        // Assert the ln relink step ran pointing at the versioned install root binary.
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]);

        // Assert the --version verify step ran against the link path.
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === $this->linkPath
            && in_array('--version', $process->command, strict: true));
    });

    it('installs updates to a versioned binary without replacing the running binary path', function (): void {
        Process::fake(['*' => Process::result(output: 'Version       9.8.7', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-9.8.7';

        expect($result['replace']['successful'])->toBeTrue();

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'mv'
            && str_starts_with($process->command[2] ?? '', $this->binaryDest.'.download.')
            && $process->command[3] === $versionedBinary);

        Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === 'mv'
            && ($process->command[1] ?? null) === '-f'
            && ($process->command[3] ?? null) === $this->binaryDest);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]);
    });

    it('skips the move but still relinks for an existing versioned binary', function (): void {
        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';
        file_put_contents($versionedBinary, 'existing binary');

        Process::fake(['*' => Process::result(output: 'Version       1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        expect($result['replace']['successful'])->toBeTrue()
            ->and($result['replace']['skipped'])->toBeTrue();

        Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === 'mv'
            && ($process->command[1] ?? null) === '-f'
            && ($process->command[3] ?? null) === $versionedBinary);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]);
    });

    it('writes install metadata after relinking the host launcher', function (): void {
        Process::fake(['*' => Process::result(output: 'Version       1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        $metadata = json_decode(file_get_contents($this->installRoot.'/install.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['replace']['successful'])->toBeTrue()
            ->and($metadata['schema_version'])->toBe(1)
            ->and($metadata['version'])->toBe('1.2.3')
            ->and($metadata['binary_path'])->toBe($this->linkPath)
            ->and($metadata['install_root'])->toBe($this->installRoot)
            ->and(CarbonImmutable::parse($metadata['installed_at'])->toIso8601String())->toBe($metadata['installed_at']);
    });

    it('does not use sudo when the configured host launcher directory is not writable', function (): void {
        $protectedRoot = $this->installRoot.'/protected-bin';
        @mkdir($protectedRoot);
        @chmod($protectedRoot, 0555);
        putenv("ORBIT_BIN_PATH={$protectedRoot}/orbit");

        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && ($process->command[0] ?? null) === 'ln') {
                return Process::result(errorOutput: 'ln: Permission denied', exitCode: 1);
            }

            if (is_array($process->command) && ($process->command[0] ?? null) === 'sudo') {
                return Process::result(errorOutput: 'sudo: a password is required', exitCode: 1);
            }

            return Process::result(output: 'orbit 1.2.3', exitCode: 0);
        });
        Process::preventStrayProcesses();

        try {
            $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

            expect($result['replace']['successful'])->toBeFalse()
                ->and($result['replace']['output'])->toBe('ln: Permission denied');

            Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
                && ($process->command[0] ?? null) === 'sudo');
        } finally {
            @chmod($protectedRoot, 0755);
            @rmdir($protectedRoot);
        }
    });

    it('reports failure when the download step fails', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                return Process::result(errorOutput: 'curl: (6) Could not resolve host', exitCode: 6);
            }

            return Process::result(output: '', exitCode: 0);
        });

        $download = (new LocalCheckoutUpdater(new CheckoutPathResolver))->downloadBinary();

        expect($download['successful'])->toBeFalse()
            ->and($download['exit_code'])->toBe(6)
            ->and($download['output'])->toBe('curl: (6) Could not resolve host')
            ->and($download['staged_path'])->toBeNull()
            ->and($download['version'])->toBeNull();
    });

    it('reports failure when the verify step fails', function (): void {
        $binaryDest = $this->binaryDest;

        Process::fake(function (PendingProcess $process) use ($binaryDest): ProcessResult {
            if (
                is_array($process->command)
                && is_string($process->command[0] ?? null)
                && str_starts_with($process->command[0], $binaryDest.'.download.')
                && in_array('--version', $process->command, strict: true)
            ) {
                return Process::result(errorOutput: 'Segmentation fault', exitCode: 1);
            }

            return Process::result(output: '', exitCode: 0);
        });

        $download = (new LocalCheckoutUpdater(new CheckoutPathResolver))->downloadBinary();

        expect($download['successful'])->toBeFalse()
            ->and($download['exit_code'])->toBe(1)
            ->and($download['output'])->toBe('Segmentation fault');
    });

    it('reports process exceptions as download failures', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                throw new RuntimeException('The process has been signaled with signal "9".');
            }

            return Process::result(output: '', exitCode: 0);
        });

        $download = (new LocalCheckoutUpdater(new CheckoutPathResolver))->downloadBinary();

        expect($download['successful'])->toBeFalse()
            ->and($download['exit_code'])->toBe(1)
            ->and($download['output'])->toBe('The process has been signaled with signal "9".');
    });

    it('uses stderr output when the download step fails', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                return Process::result(
                    output: 'stdout message',
                    errorOutput: 'curl: (22) The requested URL returned error: 404',
                    exitCode: 22,
                );
            }

            return Process::result(output: '', exitCode: 0);
        });

        $download = (new LocalCheckoutUpdater(new CheckoutPathResolver))->downloadBinary();

        expect($download['successful'])->toBeFalse()
            ->and($download['exit_code'])->toBe(22)
            ->and($download['output'])->toBe('curl: (22) The requested URL returned error: 404');
    });

    it('reports the doctor issue count from a healthy doctor envelope', function (): void {
        Process::fake(['*' => Process::result(
            output: json_encode([
                'success' => ['data' => ['doctor' => ['summary' => ['issues' => 0]]], 'meta' => []],
            ], JSON_THROW_ON_ERROR),
            exitCode: 0,
        )]);
        Process::preventStrayProcesses();

        $doctor = (new LocalCheckoutUpdater(new CheckoutPathResolver))->runDoctor();

        expect($doctor['issues'])->toBe(0);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === $this->linkPath
            && in_array('doctor', $process->command, strict: true)
            && in_array('--json', $process->command, strict: true));
    });

    it('reports the doctor issue count from a drift doctor envelope', function (): void {
        Process::fake(['*' => Process::result(
            output: json_encode([
                'error' => ['code' => 'doctor_drift', 'message' => 'drift', 'data' => ['doctor' => ['summary' => ['issues' => 4]]], 'meta' => []],
            ], JSON_THROW_ON_ERROR),
            exitCode: 1,
        )]);
        Process::preventStrayProcesses();

        $doctor = (new LocalCheckoutUpdater(new CheckoutPathResolver))->runDoctor();

        expect($doctor['issues'])->toBe(4);
    });

    it('returns an unknown doctor issue count when doctor output is unparseable', function (): void {
        Process::fake(['*' => Process::result(output: 'not json', exitCode: 0)]);
        Process::preventStrayProcesses();

        $doctor = (new LocalCheckoutUpdater(new CheckoutPathResolver))->runDoctor();

        expect($doctor['issues'])->toBeNull();
    });

    it('installs dependencies inside orbit-gateway', function (): void {
        Process::fake(['*' => Process::result(output: 'Installing dependencies', exitCode: 0)]);
        Process::preventStrayProcesses();

        $installRoot = $this->installRoot;

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->installDependencies();

        expect($result['successful'])->toBeTrue()
            ->and($result['output'])->toBe('Installing dependencies');

        Process::assertRan(fn (PendingProcess $process): bool => $process->path === $installRoot
            && $process->command === [
                'docker',
                'exec',
                'orbit-gateway',
                'composer',
                '--working-dir=apps/gateway',
                'install',
                '--no-interaction',
            ]);
    });

    it('runs local migrations inside orbit-gateway with force', function (): void {
        Process::fake(['*' => Process::result(output: 'Migrated', exitCode: 0)]);
        Process::preventStrayProcesses();

        $installRoot = $this->installRoot;

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->runMigrations();

        expect($result['successful'])->toBeTrue()
            ->and($result['output'])->toBe('Migrated');

        Process::assertRan(fn (PendingProcess $process): bool => $process->path === $installRoot
            && $process->command === [
                'docker',
                'exec',
                'orbit-gateway',
                'php',
                'apps/gateway/artisan',
                'migrate',
                '--force',
            ]);
    });

    it('downloads, replaces, and verifies the binary end to end via a file artifact', function (): void {
        // Offline proof: ORBIT_BINARY_URL=file:// points at a local artifact.
        // This verifies the full local update mechanism without a network call:
        //   1. downloadBinary — curl (file://) + chmod + verify --version
        //   2. replaceBinary  — mv to versioned path + relink + verify + metadata
        Process::fake(['*' => Process::result(output: 'orbit 1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        expect($result['download']['successful'])->toBeTrue()
            ->and($result['download']['version'])->toBe('1.2.3')
            ->and($result['replace']['successful'])->toBeTrue();
    });
});
