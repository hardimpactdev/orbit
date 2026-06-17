<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Version\InstallMetadataStore;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Performs the three-step local Orbit update sequence:
 *
 * 1. Download the prebuilt CLI binary for this host OS/arch and relink the
 *    host `orbit` launcher to a versioned binary file.
 * 2. Install gateway Composer dependencies inside `orbit-gateway`.
 * 3. Run gateway Orbit migrations inside `orbit-gateway`.
 *
 * Steps 2 and 3 operate on the gateway source checkout mounted inside
 * `orbit-gateway` at `/opt/orbit`. The gateway still runs from source; only
 * the CLI self-update changes from a source pull to a binary download-and-relink.
 *
 * Honors `ORBIT_BINARY_URL` to point at a local artifact, mirror, or specific
 * release tag instead of the default GitHub Releases URL — the same override
 * used by `bin/install-orbit` for offline E2E and smoke testing.
 */
class LocalCheckoutUpdater implements RunsLocalUpdate
{
    /**
     * Default release asset base URL.
     * Override with ORBIT_BINARY_BASE_URL to target a mirror.
     */
    private const string DEFAULT_BINARY_BASE_URL = 'https://github.com/hardimpactdev/orbit/releases/latest/download';

    private readonly InstallMetadataStore $installMetadata;

    public function __construct(
        private readonly CheckoutPathResolver $checkoutPathResolver,
        ?InstallMetadataStore $installMetadata = null,
    ) {
        $this->installMetadata = $installMetadata ?? new InstallMetadataStore;
    }

    /**
     * Download the prebuilt Orbit CLI binary for this host OS/arch and relink
     * the host `orbit` launcher (`ORBIT_BIN_PATH` or `$HOME/.local/bin/orbit`).
     *
     * The downloaded binary is installed as
     * `<install-root>/bin/orbit-binary-<version>` and the launcher is relinked
     * to that immutable path. Keeping the currently running binary path intact
     * avoids invalidating a PHAR while it is still loading classes.
     *
     * Verify with `--version` after relinking. Reports the captured output (or
     * stderr when stdout is empty) on failure.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        $installRoot = $this->checkoutPathResolver->resolve();
        $legacyBinaryPath = $installRoot.'/bin/orbit-binary';
        $stagedBinary = $this->stagedBinaryPath($legacyBinaryPath);
        $linkPath = $this->resolveLinkPath();

        $binaryUrl = $this->resolveBinaryUrl();

        try {
            if ($binaryUrl === null) {
                return [
                    'successful' => false,
                    'exit_code' => 1,
                    'output' => 'Unsupported platform: cannot determine Orbit CLI binary asset for this OS/arch.',
                ];
            }

            // Download away from the running binary, then swap after verification.
            $downloadResult = $this->runCommand([
                'curl', '-fsSL', '--retry', '3', '--retry-delay', '2', '-o', $stagedBinary, $binaryUrl,
            ], 120);

            if (! $downloadResult->successful()) {
                return $this->failedResult($downloadResult);
            }

            $chmodResult = $this->runCommand(['chmod', '0755', $stagedBinary], 10);

            if (! $chmodResult->successful()) {
                return $this->failedResult($chmodResult);
            }

            $stagedVerifyResult = $this->runCommand([$stagedBinary, '--version'], 30);

            if (! $stagedVerifyResult->successful()) {
                return $this->failedResult($stagedVerifyResult);
            }

            $version = $this->versionFromOutput($stagedVerifyResult->output());
            $versionedBinary = $this->versionedBinaryPath($installRoot, $version);

            if (is_file($versionedBinary)) {
                @unlink($stagedBinary);
                $stagedBinary = null;
            } else {
                $moveResult = $this->runCommand(['mv', '-f', $stagedBinary, $versionedBinary], 10);

                if (! $moveResult->successful()) {
                    return $this->failedResult($moveResult);
                }

                $stagedBinary = null;
            }

            $linkDirectoryResult = $this->ensureLinkDirectory($linkPath);

            if ($linkDirectoryResult !== null) {
                return $linkDirectoryResult;
            }

            $linkResult = $this->runCommand($this->linkCommand($versionedBinary, $linkPath), 10);

            if (! $linkResult->successful()) {
                return $this->failedResult($linkResult);
            }

            $verifyResult = $this->runCommand([$linkPath, '--version'], 30);

            if (! $verifyResult->successful()) {
                return $this->failedResult($verifyResult);
            }

            $this->installMetadata->write(
                version: $this->versionFromOutput($verifyResult->output()),
                binaryPath: $linkPath,
                installRoot: $installRoot,
            );

            return [
                'successful' => true,
                'exit_code' => 0,
                'output' => trim($verifyResult->output()),
            ];
        } finally {
            if (is_string($stagedBinary) && $stagedBinary !== '' && is_file($stagedBinary)) {
                @unlink($stagedBinary);
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command, int $timeout): ProcessResult
    {
        try {
            return Process::timeout($timeout)->run($command);
        } catch (Throwable $exception) {
            return Process::result(errorOutput: $exception->getMessage(), exitCode: 1);
        }
    }

    /**
     * @return array{successful: false, exit_code: int, output: string}
     */
    private function failedResult(ProcessResult $result): array
    {
        return [
            'successful' => false,
            'exit_code' => $result->exitCode() ?? 1,
            'output' => trim($result->errorOutput() ?: $result->output()),
        ];
    }

    private function stagedBinaryPath(string $binaryDest): string
    {
        return $binaryDest.'.download.'.bin2hex(random_bytes(8));
    }

    /**
     * @return array{successful: false, exit_code: int, output: string}|null
     */
    private function ensureLinkDirectory(string $linkPath): ?array
    {
        $linkDirectory = dirname($linkPath);

        if (is_dir($linkDirectory)) {
            return null;
        }

        if (@mkdir($linkDirectory, 0755, recursive: true) || is_dir($linkDirectory)) {
            return null;
        }

        return [
            'successful' => false,
            'exit_code' => 1,
            'output' => "Unable to create Orbit launcher directory: {$linkDirectory}",
        ];
    }

    private function versionFromOutput(string $output): string
    {
        if (preg_match('/\b\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?\b/', $output, $matches) === 1) {
            return $matches[0];
        }

        $configured = config('app.version');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : '0.0.0';
    }

    private function versionedBinaryPath(string $installRoot, string $version): string
    {
        $safeVersion = preg_replace('/[^A-Za-z0-9._+-]/', '-', $version) ?? '';
        $safeVersion = trim($safeVersion, '.-');

        if ($safeVersion === '') {
            $safeVersion = 'unknown';
        }

        return "{$installRoot}/bin/orbit-binary-{$safeVersion}";
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        $result = Process::path($this->checkoutPathResolver->resolve())
            ->timeout(120)
            ->run(['docker', 'exec', 'orbit-gateway', 'composer', '--working-dir=apps/gateway', 'install', '--no-interaction']);

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => trim($result->errorOutput() ?: $result->output()),
        ];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array
    {
        $result = Process::path($this->checkoutPathResolver->resolve())
            ->timeout(60)
            ->run(['docker', 'exec', 'orbit-gateway', 'php', 'apps/gateway/artisan', 'migrate', '--force']);

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => trim($result->errorOutput() ?: $result->output()),
        ];
    }

    /**
     * Resolve the download URL for the CLI binary.
     *
     * Priority:
     * 1. `ORBIT_BINARY_URL` — full override (local file path with `file://`
     *    scheme, mirror URL, or specific release tag URL).
     * 2. `ORBIT_BINARY_BASE_URL/<asset>` — base URL override with detected asset.
     * 3. Default GitHub Releases URL with detected asset.
     *
     * Returns `null` when the host OS/arch is not a supported binary target.
     */
    private function resolveBinaryUrl(): ?string
    {
        $override = getenv('ORBIT_BINARY_URL');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $asset = $this->detectBinaryAsset();

        if ($asset === null) {
            return null;
        }

        $baseUrl = getenv('ORBIT_BINARY_BASE_URL');

        if (! is_string($baseUrl) || $baseUrl === '') {
            $baseUrl = self::DEFAULT_BINARY_BASE_URL;
        }

        return rtrim($baseUrl, '/').'/'.$asset;
    }

    /**
     * Detect the prebuilt binary asset name for the host OS/arch.
     *
     * Supported targets (matching `bin/install-orbit`):
     * - macOS arm64  → `orbit-macos-arm64`
     * - Linux x86_64 → `orbit-linux-x64`
     *
     * Returns `null` for unsupported platforms.
     */
    private function detectBinaryAsset(): ?string
    {
        $os = php_uname('s');
        $machine = php_uname('m');

        if (str_starts_with($os, 'Darwin') && $machine === 'arm64') {
            return 'orbit-macos-arm64';
        }

        if (str_starts_with($os, 'Linux') && $machine === 'x86_64') {
            return 'orbit-linux-x64';
        }

        return null;
    }

    /**
     * Resolve the host launcher symlink path.
     *
     * Reads `ORBIT_BIN_PATH` (the env var honoured by `bin/install-orbit`),
     * falling back to the user-local launcher path.
     */
    private function resolveLinkPath(): string
    {
        $override = getenv('ORBIT_BIN_PATH');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/').'/.local/bin/orbit';
        }

        return '/usr/local/bin/orbit';
    }

    /**
     * @return list<string>
     */
    private function linkCommand(string $binaryDest, string $linkPath): array
    {
        return ['ln', '-sfn', $binaryDest, $linkPath];
    }
}
