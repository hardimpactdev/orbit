<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Performs the three-step local Orbit update sequence:
 *
 * 1. Download the prebuilt CLI binary for this host OS/arch and relink the
 *    host `orbit` launcher — mirrors `bin/install-orbit`'s
 *    `detect_cli_binary_asset` + `download_cli_binary` + `link_orbit`.
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

    public function __construct(
        private readonly CheckoutPathResolver $checkoutPathResolver,
    ) {}

    /**
     * Download the prebuilt Orbit CLI binary for this host OS/arch and relink
     * the host `orbit` launcher (`ORBIT_BIN_PATH` or `/usr/local/bin/orbit`).
     *
     * The downloaded binary replaces the existing one at
     * `<install-root>/bin/orbit-binary`; the launcher symlink already points
     * there and does not change. The relink step is run for idempotency and to
     * handle any path drift since install.
     *
     * Verify with `--version` after relinking. Reports the captured output (or
     * stderr when stdout is empty) on failure.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        $installRoot = $this->checkoutPathResolver->resolve();
        $binaryDest = $installRoot.'/bin/orbit-binary';
        $stagedBinary = $this->stagedBinaryPath($binaryDest);
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

            $moveResult = $this->runCommand(['mv', '-f', $stagedBinary, $binaryDest], 10);

            if (! $moveResult->successful()) {
                return $this->failedResult($moveResult);
            }

            $stagedBinary = null;

            $linkResult = $this->runCommand($this->linkCommand($binaryDest, $linkPath), 10);

            if (! $linkResult->successful()) {
                return $this->failedResult($linkResult);
            }

            $verifyResult = $this->runCommand([$linkPath, '--version'], 30);

            if (! $verifyResult->successful()) {
                return $this->failedResult($verifyResult);
            }

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
     * falling back to the installer's default `/usr/local/bin/orbit`.
     */
    private function resolveLinkPath(): string
    {
        $override = getenv('ORBIT_BIN_PATH');

        return is_string($override) && $override !== '' ? $override : '/usr/local/bin/orbit';
    }

    /**
     * @return list<string>
     */
    private function linkCommand(string $binaryDest, string $linkPath): array
    {
        $linkDirectory = dirname($linkPath);

        if (is_writable($linkDirectory)) {
            return ['ln', '-sfn', $binaryDest, $linkPath];
        }

        return ['sudo', '-n', 'ln', '-sfn', $binaryDest, $linkPath];
    }
}
