<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;
use App\Services\Updates\LocalCheckoutUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

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

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        $metadata = json_decode(file_get_contents($this->installRoot.'/install.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['successful'])->toBeTrue()
            ->and($metadata['binary_path'])->toBe($expectedLinkPath);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['ln', '-sfn', $this->binaryDest, $expectedLinkPath]);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === [$expectedLinkPath, '--version']);

        Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === 'sudo');

        @unlink($expectedLinkPath);
        @rmdir(dirname($expectedLinkPath));
        @rmdir(dirname(dirname($expectedLinkPath)));
        @rmdir($home);
    });

    it('downloads the binary and relinks the host launcher on pull_source', function (): void {
        Process::fake(['*' => Process::result(output: 'orbit 1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result['successful'])->toBeTrue()
            ->and($result['exit_code'])->toBe(0);

        $stagedBinary = null;

        // Assert the curl download step ran with the ORBIT_BINARY_URL override.
        Process::assertRan(function (PendingProcess $process) use (&$stagedBinary): bool {
            if (! is_array($process->command) || $process->command[0] !== 'curl' || ! in_array($this->binaryUrl, $process->command, strict: true)) {
                return false;
            }

            $outputOption = array_search('-o', $process->command, true);

            if (! is_int($outputOption) || ! is_string($process->command[$outputOption + 1] ?? null)) {
                return false;
            }

            $stagedBinary = $process->command[$outputOption + 1];

            return $stagedBinary !== $this->binaryDest
                && str_starts_with($stagedBinary, $this->binaryDest.'.download.');
        });

        expect($stagedBinary)->toBeString();

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['chmod', '0755', $stagedBinary]);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === [$stagedBinary, '--version']);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command === ['mv', '-f', $stagedBinary, $this->binaryDest]);

        // Assert the ln relink step ran pointing at the install root binary.
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === 'ln'
            && $process->command[1] === '-sfn'
            && $process->command[2] === $this->binaryDest
            && $process->command[3] === $this->linkPath);

        // Assert the --version verify step ran against the link path.
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === $this->linkPath
            && in_array('--version', $process->command, strict: true));
    });

    it('writes install metadata after relinking the host launcher', function (): void {
        Process::fake(['*' => Process::result(output: 'Version       1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        $metadata = json_decode(file_get_contents($this->installRoot.'/install.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['successful'])->toBeTrue()
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
            $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

            expect($result['successful'])->toBeFalse()
                ->and($result['output'])->toBe('ln: Permission denied');

            Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
                && ($process->command[0] ?? null) === 'sudo');
        } finally {
            @chmod($protectedRoot, 0755);
            @rmdir($protectedRoot);
        }
    });

    it('reports failure when the download step fails', function (): void {
        $binaryUrl = $this->binaryUrl;
        $binaryDest = $this->binaryDest;

        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                return Process::result(errorOutput: 'curl: (6) Could not resolve host', exitCode: 6);
            }

            return Process::result(output: '', exitCode: 0);
        });

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result['successful'])->toBeFalse()
            ->and($result['exit_code'])->toBe(6)
            ->and($result['output'])->toBe('curl: (6) Could not resolve host');
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

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result['successful'])->toBeFalse()
            ->and($result['exit_code'])->toBe(1)
            ->and($result['output'])->toBe('Segmentation fault');
    });

    it('reports process exceptions as pull_source failures', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                throw new RuntimeException('The process has been signaled with signal "9".');
            }

            return Process::result(output: '', exitCode: 0);
        });

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result['successful'])->toBeFalse()
            ->and($result['exit_code'])->toBe(1)
            ->and($result['output'])->toBe('The process has been signaled with signal "9".');
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

    it('uses stderr output when a step fails', function (): void {
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

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result)->toBe([
            'successful' => false,
            'exit_code' => 22,
            'output' => 'curl: (22) The requested URL returned error: 404',
        ]);
    });

    it('preserves the gateway-source dependency install and migration steps after a successful binary download', function (): void {
        // Offline proof: ORBIT_BINARY_URL=file:// points at a local artifact.
        // This test verifies the three-step contract end-to-end:
        //   1. pullSource          — download binary + chmod + relink + verify
        //   2. installDependencies — docker exec orbit-gateway composer install
        //   3. runMigrations       — docker exec orbit-gateway php artisan migrate
        Process::fake(['*' => Process::result(output: 'orbit 1.2.3', exitCode: 0)]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);

        $pull = $updater->pullSource();

        Process::fake(['*' => Process::result(output: 'Nothing to install', exitCode: 0)]);
        $deps = $updater->installDependencies();

        Process::fake(['*' => Process::result(output: 'Nothing to migrate', exitCode: 0)]);
        $migrate = $updater->runMigrations();

        expect($pull['successful'])->toBeTrue()
            ->and($deps['successful'])->toBeTrue()
            ->and($deps['output'])->toBe('Nothing to install')
            ->and($migrate['successful'])->toBeTrue()
            ->and($migrate['output'])->toBe('Nothing to migrate');
    });
});
