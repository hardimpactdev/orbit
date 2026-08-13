<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;
use App\Services\Updates\LocalCheckoutUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Progress\ForkedFrameTicker;

/**
 * Download a binary and then replace it through the split surface, returning the
 * combined success of both steps. Mirrors the order the workflow runs them.
 *
 * @return array{download: array<string, mixed>, replace: array<string, mixed>}
 */
function local_checkout_version_json(string $version): string
{
    return json_encode([
        'success' => [
            'data' => [
                'version' => $version,
                'latest_version' => null,
                'update_available' => false,
                'released_at' => null,
                'installed_at' => null,
            ],
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR)."\n";
}

function runDownloadAndReplace(LocalCheckoutUpdater $updater): array
{
    $download = $updater->downloadBinary();

    $replace = $download['successful'] && is_string($download['staged_path']) && is_string($download['version'])
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
        $this->previousBinarySha256 = getenv('ORBIT_BINARY_SHA256');
        $this->previousBinPath = getenv('ORBIT_BIN_PATH');
        $this->previousManifestUrl = getenv('ORBIT_RELEASE_MANIFEST_URL');
        $this->previousMetadataPath = getenv('ORBIT_INSTALL_METADATA_PATH');
        $this->previousHome = getenv('HOME');
        $this->previousShell = getenv('SHELL');

        // Keep shell integration writes inside the sandbox and default to bash so
        // ordinary updater tests do not create a real operator ~/.zshrc.
        $this->sandboxHome = $this->installRoot.'/home';
        @mkdir($this->sandboxHome, recursive: true);

        putenv("ORBIT_INSTALL_PATH={$this->installRoot}");
        putenv("ORBIT_BINARY_URL={$this->binaryUrl}");
        putenv('ORBIT_BINARY_SHA256');
        putenv("ORBIT_BIN_PATH={$this->linkPath}");
        putenv("ORBIT_INSTALL_METADATA_PATH={$this->installRoot}/install.json");
        putenv('ORBIT_RELEASE_MANIFEST_URL');
        putenv("HOME={$this->sandboxHome}");
        putenv('SHELL=/bin/bash');
    });

    afterEach(function (): void {
        $this->previousInstall === false
            ? putenv('ORBIT_INSTALL_PATH')
            : putenv("ORBIT_INSTALL_PATH={$this->previousInstall}");
        $this->previousBinaryUrl === false
            ? putenv('ORBIT_BINARY_URL')
            : putenv("ORBIT_BINARY_URL={$this->previousBinaryUrl}");
        $this->previousBinarySha256 === false
            ? putenv('ORBIT_BINARY_SHA256')
            : putenv("ORBIT_BINARY_SHA256={$this->previousBinarySha256}");
        $this->previousBinPath === false ? putenv('ORBIT_BIN_PATH') : putenv("ORBIT_BIN_PATH={$this->previousBinPath}");
        $this->previousMetadataPath === false
            ? putenv('ORBIT_INSTALL_METADATA_PATH')
            : putenv("ORBIT_INSTALL_METADATA_PATH={$this->previousMetadataPath}");
        $this->previousManifestUrl === false
            ? putenv('ORBIT_RELEASE_MANIFEST_URL')
            : putenv("ORBIT_RELEASE_MANIFEST_URL={$this->previousManifestUrl}");
        $this->previousHome === false ? putenv('HOME') : putenv("HOME={$this->previousHome}");
        $this->previousShell === false ? putenv('SHELL') : putenv("SHELL={$this->previousShell}");

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

        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';
        $metadata = json_decode(
            file_get_contents($this->installRoot.'/install.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($result['download']['successful'])
            ->toBeTrue()
            ->and($result['replace']['successful'])
            ->toBeTrue()
            ->and($metadata['binary_path'])
            ->toBe($expectedLinkPath);

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, $expectedLinkPath]
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === [$expectedLinkPath, '--version', '--local', '--json']
            ),
        );

        Process::assertNotRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[0] ?? null) === 'sudo'
            ),
        );

        @unlink($expectedLinkPath);
        @rmdir(dirname($expectedLinkPath));
        @rmdir(dirname(dirname($expectedLinkPath)));
        @rmdir($home);
    });

    it('reports a missing configured launcher as an unhealthy current installation', function (): void {
        $result = new LocalCheckoutUpdater(new CheckoutPathResolver)->verifyCurrentInstallation('1.2.3');

        expect($result['successful'])
            ->toBeFalse()
            ->and($result['exit_code'])
            ->toBe(1)
            ->and($result['output'])
            ->toContain($this->linkPath);
    });

    it('accepts a configured launcher that reports the expected structured version', function (): void {
        file_put_contents($this->linkPath, '#!/bin/sh');
        chmod($this->linkPath, 0755);

        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = new LocalCheckoutUpdater(new CheckoutPathResolver)->verifyCurrentInstallation('1.2.3');

        expect($result['successful'])->toBeTrue()->and($result['exit_code'])->toBe(0);

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === [$this->linkPath, '--version', '--local', '--json']
            ),
        );
    });

    it('rejects a configured launcher that reports a different version', function (): void {
        file_put_contents($this->linkPath, '#!/bin/sh');
        chmod($this->linkPath, 0755);

        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.2'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = new LocalCheckoutUpdater(new CheckoutPathResolver)->verifyCurrentInstallation('1.2.3');

        expect($result['successful'])
            ->toBeFalse()
            ->and($result['output'])
            ->toContain('reports 1.2.2; expected 1.2.3');
    });

    it('downloads the binary to a staged path and reports the resolved version', function (): void {
        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

        expect($download['successful'])
            ->toBeTrue()
            ->and($download['exit_code'])
            ->toBe(0)
            ->and($download['version'])
            ->toBe('1.2.3')
            ->and($download['staged_path'])
            ->toBeString()
            ->and($download['staged_path'])
            ->toStartWith($this->binaryDest.'.download.');

        $stagedBinary = $download['staged_path'];

        // Assert the curl download step ran with the ORBIT_BINARY_URL override.
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command[0] === 'curl'
                && in_array($this->binaryUrl, $process->command, strict: true)
                && in_array($stagedBinary, $process->command, strict: true)
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['chmod', '0755', $stagedBinary]
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === [$stagedBinary, '--version', '--local', '--json']
            ),
        );
    });

    it('rejects a downloaded binary that does not match its declared checksum', function (): void {
        putenv('ORBIT_BINARY_SHA256='.str_repeat('a', 64));

        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && ($process->command[0] ?? null) === 'curl') {
                $outputIndex = array_search('-o', $process->command, true);
                file_put_contents($process->command[$outputIndex + 1], 'candidate binary');
            }

            return Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0);
        });
        Process::preventStrayProcesses();

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary('1.2.3');

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['output'])
            ->toContain('checksum');
    });

    it('rejects a downloaded binary that reports a different requested version', function (): void {
        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.2'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary('1.2.3');

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['output'])
            ->toContain('reports 1.2.2; expected 1.2.3');
    });

    it('downloads the host artifact from the configured release manifest', function (): void {
        $manifestUrl = 'https://artifacts.orbit/channels/live-test/orbit-release-manifest.json';
        $artifactUrl = 'https://artifacts.orbit/candidates/build-1/orbit-macos-arm64';
        $artifactBytes = 'candidate binary';

        putenv('ORBIT_BINARY_URL');
        putenv("ORBIT_RELEASE_MANIFEST_URL={$manifestUrl}");

        Http::fake([
            $manifestUrl => Http::response([
                'schema_version' => 1,
                'version' => '1.2.3',
                'cli_artifacts' => [
                    'darwin-arm64' => [
                        'url' => $artifactUrl,
                        'sha256' => hash('sha256', $artifactBytes),
                    ],
                    'linux-amd64' => [
                        'url' => $artifactUrl,
                        'sha256' => hash('sha256', $artifactBytes),
                    ],
                ],
            ]),
        ]);

        Process::fake(function (PendingProcess $process) use ($artifactBytes): ProcessResult {
            if (is_array($process->command) && ($process->command[0] ?? null) === 'curl') {
                $outputIndex = array_search('-o', $process->command, true);
                file_put_contents($process->command[$outputIndex + 1], $artifactBytes);
            }

            return Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0);
        });
        Process::preventStrayProcesses();

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary('1.2.3');

        expect($download['successful'])->toBeTrue()->and($download['version'])->toBe('1.2.3');

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[0] ?? null) === 'curl'
                && in_array($artifactUrl, $process->command, strict: true)
            ),
        );
    });

    it('ticks active progress while local update commands are still running', function (): void {
        $ticks = 0;
        $ticker = new ForkedFrameTicker(1_000);
        $ticker->start(function () use (&$ticks): void {
            $ticks++;
        });

        Process::fake(function (): mixed {
            static $call = 0;
            $call++;

            return match ($call) {
                1 => Process::describe()->runsFor(3)->exitCode(0),
                3 => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0),
                default => Process::result(output: '', exitCode: 0),
            };
        });
        Process::preventStrayProcesses();

        try {
            $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

            expect($download['successful'])
                ->toBeTrue()
                ->and($download['version'])
                ->toBe('1.2.3')
                ->and($ticks)
                ->toBeGreaterThanOrEqual(3);
        } finally {
            $ticker->stop();
        }
    });

    it('replaces the binary with a versioned file and relinks the host launcher', function (): void {
        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);
        $download = $updater->downloadBinary();
        $stagedBinary = $download['staged_path'];
        $replace = $updater->replaceBinary($stagedBinary, $download['version']);

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';

        expect($replace['successful'])->toBeTrue()->and($replace['skipped'])->toBeFalse();

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['mv', '-f', $stagedBinary, $versionedBinary]
            ),
        );

        // Assert the ln relink step ran pointing at the versioned install root binary.
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]
            ),
        );

        // Assert the --version verify step ran against the link path.
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command[0] === $this->linkPath
                && in_array('--version', $process->command, strict: true)
            ),
        );
    });

    it('ensures zsh shell integration after a successful binary replace', function (): void {
        putenv('SHELL=/bin/zsh');
        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);
        $download = $updater->downloadBinary();
        $replace = $updater->replaceBinary($download['staged_path'], $download['version']);

        $snippet = $this->sandboxHome.'/.config/orbit/shell/zsh-noglob.zsh';
        $zshrc = $this->sandboxHome.'/.zshrc';

        expect($replace['successful'])
            ->toBeTrue()
            ->and(is_file($snippet))
            ->toBeTrue()
            ->and(file_get_contents($snippet))
            ->toContain("alias orbit='noglob orbit'")
            ->and(is_file($zshrc))
            ->toBeTrue()
            ->and(file_get_contents($zshrc))
            ->toContain('# >>> orbit zsh integration >>>');
    });

    it('does not relink a shadowing launcher resolved through PATH', function (): void {
        Process::fake([
            '*command -v orbit*' => Process::result(output: "/tmp/orbit-shadow-bin/orbit\n", exitCode: 0),
            '*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);
        $download = $updater->downloadBinary();
        $replace = $updater->replaceBinary($download['staged_path'], $download['version']);

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';

        expect($replace['successful'])->toBeTrue();

        Process::assertNotRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, '/tmp/orbit-shadow-bin/orbit']
            ),
        );
    });

    it('does not relink when the resolved launcher is the relinked launcher', function (): void {
        Process::fake([
            '*command -v orbit*' => Process::result(output: $this->linkPath."\n", exitCode: 0),
            '*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);
        $download = $updater->downloadBinary();
        $updater->replaceBinary($download['staged_path'], $download['version']);

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';

        // Only the managed launcher is linked; no second ln to a different path.
        $lnToOther = 0;
        Process::assertRan(function (PendingProcess $process) use ($versionedBinary, &$lnToOther): bool {
            if (
                is_array($process->command)
                && ($process->command[0] ?? null) === 'ln'
                && ($process->command[2] ?? null) === $versionedBinary
                && ($process->command[3] ?? null) !== $this->linkPath
            ) {
                $lnToOther++;
            }

            return true;
        });

        expect($lnToOther)->toBe(0);
    });

    it('installs updates to a versioned binary without replacing the running binary path', function (): void {
        Process::fake(['*' => Process::result(output: local_checkout_version_json('9.8.7'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        $versionedBinary = $this->installRoot.'/bin/orbit-binary-9.8.7';

        expect($result['replace']['successful'])->toBeTrue();

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command[0] === 'mv'
                && str_starts_with($process->command[2] ?? '', $this->binaryDest.'.download.')
                && $process->command[3] === $versionedBinary
            ),
        );

        Process::assertNotRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[0] ?? null) === 'mv'
                && ($process->command[1] ?? null) === '-f'
                && ($process->command[3] ?? null) === $this->binaryDest
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]
            ),
        );
    });

    it('skips the move but still relinks for an existing versioned binary', function (): void {
        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';
        file_put_contents($versionedBinary, 'existing binary');

        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        expect($result['replace']['successful'])->toBeTrue()->and($result['replace']['skipped'])->toBeTrue();

        Process::assertNotRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[0] ?? null) === 'mv'
                && ($process->command[1] ?? null) === '-f'
                && ($process->command[3] ?? null) === $versionedBinary
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]
            ),
        );
    });

    it('skips the move when the existing versioned binary has identical bytes', function (): void {
        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';
        $stagedBinary = $this->binaryDest.'.download.identical';

        file_put_contents($versionedBinary, 'candidate binary');
        file_put_contents($stagedBinary, 'candidate binary');

        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $replace = new LocalCheckoutUpdater(new CheckoutPathResolver)->replaceBinary($stagedBinary, '1.2.3');

        expect($replace['successful'])
            ->toBeTrue()
            ->and($replace['skipped'])
            ->toBeTrue()
            ->and(file_exists($stagedBinary))
            ->toBeFalse();

        Process::assertNotRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[0] ?? null) === 'mv'
                && ($process->command[1] ?? null) === '-f'
                && ($process->command[3] ?? null) === $versionedBinary
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]
            ),
        );
    });

    it('overwrites an existing versioned binary when a same-version candidate differs', function (): void {
        $versionedBinary = $this->installRoot.'/bin/orbit-binary-1.2.3';
        $stagedBinary = $this->binaryDest.'.download.candidate';

        file_put_contents($versionedBinary, 'released binary');
        file_put_contents($stagedBinary, 'candidate binary');

        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $replace = new LocalCheckoutUpdater(new CheckoutPathResolver)->replaceBinary($stagedBinary, '1.2.3');

        expect($replace['successful'])->toBeTrue()->and($replace['skipped'])->toBeFalse();

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['mv', '-f', $stagedBinary, $versionedBinary]
            ),
        );

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command === ['ln', '-sfn', $versionedBinary, $this->linkPath]
            ),
        );
    });

    it('writes install metadata after relinking the host launcher', function (): void {
        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $updater = new LocalCheckoutUpdater(new CheckoutPathResolver);
        $download = $updater->downloadBinary();
        $replace = $updater->replaceBinary(
            $download['staged_path'],
            $download['version'],
            '2026-06-25T20:00:25Z',
        );

        $metadata = json_decode(
            file_get_contents($this->installRoot.'/install.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($replace['successful'])
            ->toBeTrue()
            ->and($metadata['schema_version'])
            ->toBe(1)
            ->and($metadata['version'])
            ->toBe('1.2.3')
            ->and($metadata['released_at'])
            ->toBe('2026-06-25T20:00:25+00:00')
            ->and($metadata['binary_path'])
            ->toBe($this->linkPath)
            ->and($metadata['install_root'])
            ->toBe($this->installRoot)
            ->and(CarbonImmutable::parse($metadata['installed_at'])->toIso8601String())
            ->toBe($metadata['installed_at']);
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

            return Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0);
        });
        Process::preventStrayProcesses();

        try {
            $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

            expect($result['replace']['successful'])
                ->toBeFalse()
                ->and($result['replace']['output'])
                ->toBe('ln: Permission denied');

            Process::assertNotRan(
                fn (PendingProcess $process): bool => (
                    is_array($process->command)
                    && ($process->command[0] ?? null) === 'sudo'
                ),
            );
        } finally {
            if (is_dir($protectedRoot)) {
                chmod($protectedRoot, 0755);
                rmdir($protectedRoot);
            }
        }
    });

    it('reports failure when the download step fails', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                return Process::result(errorOutput: 'curl: (6) Could not resolve host', exitCode: 6);
            }

            return Process::result(output: '', exitCode: 0);
        });

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['exit_code'])
            ->toBe(6)
            ->and($download['output'])
            ->toBe('curl: (6) Could not resolve host')
            ->and($download['staged_path'])
            ->toBeNull()
            ->and($download['version'])
            ->toBeNull();
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

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['exit_code'])
            ->toBe(1)
            ->and($download['output'])
            ->toBe('Segmentation fault');
    });

    it('reports process exceptions as download failures', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            if (is_array($process->command) && $process->command[0] === 'curl') {
                throw new RuntimeException('The process has been signaled with signal "9".');
            }

            return Process::result(output: '', exitCode: 0);
        });

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['exit_code'])
            ->toBe(1)
            ->and($download['output'])
            ->toBe('The process has been signaled with signal "9".');
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

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['exit_code'])
            ->toBe(22)
            ->and($download['output'])
            ->toBe('curl: (22) The requested URL returned error: 404');
    });

    it('reports the doctor issue count from a healthy doctor envelope', function (): void {
        Process::fake(['*' => Process::result(
            output: json_encode([
                'success' => ['data' => ['doctor' => ['summary' => ['issues' => 0]]], 'meta' => []],
            ], JSON_THROW_ON_ERROR),
            exitCode: 0,
        )]);
        Process::preventStrayProcesses();

        $doctor = new LocalCheckoutUpdater(new CheckoutPathResolver)->runDoctor();

        expect($doctor['issues'])->toBe(0);

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && $process->command[0] === $this->linkPath
                && in_array('doctor', $process->command, strict: true)
                && in_array('--json', $process->command, strict: true)
            ),
        );
    });

    it('reports the doctor issue count from a drift doctor envelope', function (): void {
        Process::fake(['*' => Process::result(
            output: json_encode([
                'error' => [
                    'code' => 'doctor_drift',
                    'message' => 'drift',
                    'data' => ['doctor' => ['summary' => ['issues' => 4]]],
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            exitCode: 1,
        )]);
        Process::preventStrayProcesses();

        $doctor = new LocalCheckoutUpdater(new CheckoutPathResolver)->runDoctor();

        expect($doctor['issues'])->toBe(4);
    });

    it('returns an unknown doctor issue count when doctor output is unparseable', function (): void {
        Process::fake(['*' => Process::result(output: 'not json', exitCode: 0)]);
        Process::preventStrayProcesses();

        $doctor = new LocalCheckoutUpdater(new CheckoutPathResolver)->runDoctor();

        expect($doctor['issues'])->toBeNull();
    });

    it('fails download closed when --version --local --json succeeds with malformed output', function (): void {
        Process::fake(['*' => Process::result(output: "Version       1.2.3\nnot-json\n", exitCode: 0)]);
        Process::preventStrayProcesses();
        config()->set('app.version', '9.9.9');

        $download = new LocalCheckoutUpdater(new CheckoutPathResolver)->downloadBinary();

        expect($download['successful'])
            ->toBeFalse()
            ->and($download['version'])
            ->toBeNull()
            ->and($download['staged_path'])
            ->toBeNull()
            ->and($download['output'])
            ->toContain('structured JSON')
            ->and($download['output'])
            ->not->toContain('9.9.9');
    });

    it('fails replace closed when launcher version JSON is missing after a successful exit', function (): void {
        Process::fake(function (PendingProcess $process): ProcessResult {
            $command = $process->command;

            if (is_array($command) && in_array('--version', $command, true) && in_array('--json', $command, true)) {
                // Human table only — structured parser must reject this.
                return Process::result(output: "Version       1.2.3\n", exitCode: 0);
            }

            return Process::result(output: '', exitCode: 0);
        });
        Process::preventStrayProcesses();
        config()->set('app.version', '9.9.9');

        $staged = $this->binaryDest.'.download.test';
        file_put_contents($staged, "binary\n");

        $replace = new LocalCheckoutUpdater(new CheckoutPathResolver)->replaceBinary($staged, '1.2.3');

        expect($replace['successful'])
            ->toBeFalse()
            ->and($replace['skipped'])
            ->toBeFalse()
            ->and($replace['output'])
            ->toContain('structured JSON')
            ->and(is_file($this->installRoot.'/install.json'))
            ->toBeFalse();
    });

    it('installs dependencies inside orbit-gateway', function (): void {
        Process::fake(['*' => Process::result(output: 'Installing dependencies', exitCode: 0)]);
        Process::preventStrayProcesses();

        $installRoot = $this->installRoot;

        $result = new LocalCheckoutUpdater(new CheckoutPathResolver)->installDependencies();

        expect($result['successful'])->toBeTrue()->and($result['output'])->toBe('Installing dependencies');

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                $process->path === $installRoot
                && $process->command === [
                    'docker',
                    'exec',
                    'orbit-gateway',
                    'composer',
                    '--working-dir=apps/gateway',
                    'install',
                    '--no-interaction',
                ]
            ),
        );
    });

    it('runs local migrations inside orbit-gateway with force', function (): void {
        Process::fake(['*' => Process::result(output: 'Migrated', exitCode: 0)]);
        Process::preventStrayProcesses();

        $installRoot = $this->installRoot;

        $result = new LocalCheckoutUpdater(new CheckoutPathResolver)->runMigrations();

        expect($result['successful'])->toBeTrue()->and($result['output'])->toBe('Migrated');

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                $process->path === $installRoot
                && $process->command === [
                    'docker',
                    'exec',
                    'orbit-gateway',
                    'php',
                    'apps/gateway/artisan',
                    'migrate',
                    '--force',
                ]
            ),
        );
    });

    it('downloads, replaces, and verifies the binary end to end via a file artifact', function (): void {
        // Offline proof: ORBIT_BINARY_URL=file:// points at a local artifact.
        // This verifies the full local update mechanism without a network call:
        //   1. downloadBinary — curl (file://) + chmod + verify --version
        //   2. replaceBinary  — mv to versioned path + relink + verify + metadata
        Process::fake(['*' => Process::result(output: local_checkout_version_json('1.2.3'), exitCode: 0)]);
        Process::preventStrayProcesses();

        $result = runDownloadAndReplace(new LocalCheckoutUpdater(new CheckoutPathResolver));

        expect($result['download']['successful'])
            ->toBeTrue()
            ->and($result['download']['version'])
            ->toBe('1.2.3')
            ->and($result['replace']['successful'])
            ->toBeTrue();
    });
});
