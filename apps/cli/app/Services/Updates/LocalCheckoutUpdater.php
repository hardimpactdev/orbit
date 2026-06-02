<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Facades\Process;

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
     * there and does not change. The relink step (`ln -sf`) is run for
     * idempotency and to handle any path drift since install.
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
        $linkPath = $this->resolveLinkPath();

        $binaryUrl = $this->resolveBinaryUrl();

        if ($binaryUrl === null) {
            return [
                'successful' => false,
                'exit_code' => 1,
                'output' => 'Unsupported platform: cannot determine Orbit CLI binary asset for this OS/arch.',
            ];
        }

        // Download the binary into the install root.
        $downloadResult = Process::timeout(120)->run([
            'curl', '-fsSL', '--retry', '3', '--retry-delay', '2', '-o', $binaryDest, $binaryUrl,
        ]);

        if (! $downloadResult->successful()) {
            return [
                'successful' => false,
                'exit_code' => $downloadResult->exitCode() ?? 1,
                'output' => trim($downloadResult->errorOutput() ?: $downloadResult->output()),
            ];
        }

        // Make executable.
        $chmodResult = Process::timeout(10)->run(['chmod', '0755', $binaryDest]);

        if (! $chmodResult->successful()) {
            return [
                'successful' => false,
                'exit_code' => $chmodResult->exitCode() ?? 1,
                'output' => trim($chmodResult->errorOutput() ?: $chmodResult->output()),
            ];
        }

        // Relink the host launcher to the updated binary (idempotent).
        $linkResult = Process::timeout(10)->run(['ln', '-sf', $binaryDest, $linkPath]);

        if (! $linkResult->successful()) {
            return [
                'successful' => false,
                'exit_code' => $linkResult->exitCode() ?? 1,
                'output' => trim($linkResult->errorOutput() ?: $linkResult->output()),
            ];
        }

        // Verify the updated binary responds to --version.
        $verifyResult = Process::timeout(30)->run([$linkPath, '--version']);

        if (! $verifyResult->successful()) {
            return [
                'successful' => false,
                'exit_code' => $verifyResult->exitCode() ?? 1,
                'output' => trim($verifyResult->errorOutput() ?: $verifyResult->output()),
            ];
        }

        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => trim($verifyResult->output()),
        ];
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
}
