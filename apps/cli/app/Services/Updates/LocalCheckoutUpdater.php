<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Version\InstallMetadataStore;
use App\Services\Version\ReleaseManifestResolver;
use App\Services\Version\VersionOutputParser;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use JsonException;
use Orbit\Core\Progress\ForkedFrameTicker;
use Throwable;

/**
 * Performs the local Orbit CLI binary update for the host checkout.
 *
 * The update is split into two phases so the orchestrator can surface each as a
 * progress-tree row:
 *
 * 1. {@see self::downloadBinary()} — download the prebuilt CLI binary for this
 *    host OS/arch to a staged path away from the running binary, make it
 *    executable, and verify it responds to `--version`.
 * 2. {@see self::replaceBinary()} — move the verified binary to a versioned file,
 *    relink the host `orbit` launcher to it, verify the launcher, and write the
 *    install metadata. Keeping the currently running binary path intact avoids
 *    invalidating a PHAR while it is still loading classes.
 *
 * {@see self::runDoctor()} runs `orbit doctor` in verify mode through the
 * relinked launcher as a non-fatal post-update verification step.
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

    private readonly VersionOutputParser $versionOutputParser;

    private readonly ReleaseManifestResolver $releaseManifests;

    private readonly ZshShellIntegration $zshShellIntegration;

    public function __construct(
        private readonly CheckoutPathResolver $checkoutPathResolver,
        ?InstallMetadataStore $installMetadata = null,
        ?VersionOutputParser $versionOutputParser = null,
        ?ZshShellIntegration $zshShellIntegration = null,
        ?ReleaseManifestResolver $releaseManifests = null,
    ) {
        $this->installMetadata = $installMetadata ?? new InstallMetadataStore;
        $this->versionOutputParser = $versionOutputParser ?? new VersionOutputParser;
        $this->zshShellIntegration = $zshShellIntegration ?? new ZshShellIntegration;
        $this->releaseManifests = $releaseManifests ?? new ReleaseManifestResolver;
    }

    /**
     * Download, verify, and install the CLI binary in a single call. Composes
     * {@see self::downloadBinary()} and {@see self::replaceBinary()} for callers
     * that treat the local CLI update as one atomic step (the `update:all`
     * runner).
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        $download = $this->downloadBinary();

        if (! $download['successful'] || ! is_string($download['staged_path']) || ! is_string($download['version'])) {
            return [
                'successful' => false,
                'exit_code' => $download['exit_code'],
                'output' => $download['output'],
            ];
        }

        $replace = $this->replaceBinary($download['staged_path'], $download['version']);

        return [
            'successful' => $replace['successful'],
            'exit_code' => $replace['exit_code'],
            'output' => $replace['output'],
        ];
    }

    /**
     * Download the prebuilt Orbit CLI binary for this host OS/arch to a staged
     * path, verify its declared checksum when present, make it executable, and
     * verify it responds to `--version`. The staged copy lives next to the
     * install root binary so the move in {@see self::replaceBinary()} stays on
     * the same filesystem.
     *
     * @return array{successful: bool, exit_code: int, output: string, staged_path: string|null, version: string|null}
     */
    public function downloadBinary(string $expectedVersion = ''): array
    {
        $installRoot = $this->checkoutPathResolver->resolve();
        $legacyBinaryPath = $installRoot.'/bin/orbit-binary';
        $stagedBinary = $this->stagedBinaryPath($legacyBinaryPath);

        $binaryArtifact = $this->resolveBinaryArtifact();

        if ($binaryArtifact === null) {
            return $this->failedDownload(
                $this->configuredManifestUrl() === null
                    ? 'Unsupported platform: cannot determine Orbit CLI binary asset for this OS/arch.'
                    : 'Configured release manifest does not contain a valid CLI artifact for this platform.',
                1,
            );
        }

        // Download away from the running binary, then swap after verification.
        $downloadResult = $this->runCommand([
            'curl',
            '-fsSL',
            '--retry',
            '3',
            '--retry-delay',
            '2',
            '-o',
            $stagedBinary,
            $binaryArtifact['url'],
        ], 120);

        if (! $downloadResult->successful()) {
            $this->discard($stagedBinary);

            return $this->failedDownloadFrom($downloadResult);
        }

        if ($binaryArtifact['sha256'] !== null && hash_file('sha256', $stagedBinary) !== $binaryArtifact['sha256']) {
            $this->discard($stagedBinary);

            return $this->failedDownload('CLI artifact checksum verification failed.', 1);
        }

        $chmodResult = $this->runCommand(['chmod', '0755', $stagedBinary], 10);

        if (! $chmodResult->successful()) {
            $this->discard($stagedBinary);

            return $this->failedDownloadFrom($chmodResult);
        }

        $verifyResult = $this->runCommand([$stagedBinary, '--version', '--local', '--json'], 30);

        if (! $verifyResult->successful()) {
            $this->discard($stagedBinary);

            return $this->failedDownloadFrom($verifyResult);
        }

        $version = $this->requireVersionFromOutput($verifyResult->output());

        if ($version === null) {
            $this->discard($stagedBinary);

            return $this->failedDownload(
                'CLI version verification failed: version output was not structured JSON.',
                1,
            );
        }

        if ($expectedVersion !== '' && ! version_compare($version, $expectedVersion, operator: '==')) {
            $this->discard($stagedBinary);

            return $this->failedDownload(
                "Downloaded Orbit CLI reports {$version}; expected {$expectedVersion}.",
                1,
            );
        }

        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => trim($verifyResult->output()),
            'staged_path' => $stagedBinary,
            'version' => $version,
        ];
    }

    /**
     * Move the verified staged binary to
     * `<install-root>/bin/orbit-binary-<version>`, relink the host launcher to
     * it, verify the launcher, and write the install metadata. When the
     * versioned binary is already present with identical bytes the move is
     * skipped and the staged copy discarded, but the relink and verify still run
     * so the launcher always points at the requested version. Same-version
     * release candidates may carry different bytes, so an existing versioned
     * binary is overwritten when the downloaded artifact differs. The returned
     * `skipped` flag is a low-level move optimization only —
     * {@see LocalUpdateRunner} always reports the public `Replacing binary`
     * step as `Done` on success.
     *
     * @return array{successful: bool, exit_code: int, output: string, skipped: bool}
     */
    public function replaceBinary(string $stagedPath, string $version, ?string $releasedAt = null): array
    {
        $installRoot = $this->checkoutPathResolver->resolve();
        $linkPath = $this->resolveLinkPath();
        $versionedBinary = $this->versionedBinaryPath($installRoot, $version);
        $skipped = false;

        try {
            if ($this->versionedBinaryMatches($versionedBinary, $stagedPath)) {
                $this->discard($stagedPath);
                $skipped = true;
            } else {
                $moveResult = $this->runCommand(['mv', '-f', $stagedPath, $versionedBinary], 10);

                if (! $moveResult->successful()) {
                    return $this->failedReplaceFrom($moveResult);
                }

                $stagedPath = '';
            }

            $linkDirectoryResult = $this->ensureLinkDirectory($linkPath);

            if ($linkDirectoryResult !== null) {
                return $linkDirectoryResult;
            }

            $linkResult = $this->runCommand($this->linkCommand($versionedBinary, $linkPath), 10);

            if (! $linkResult->successful()) {
                return $this->failedReplaceFrom($linkResult);
            }

            $verifyResult = $this->runCommand([$linkPath, '--version', '--local', '--json'], 30);

            if (! $verifyResult->successful()) {
                return $this->failedReplaceFrom($verifyResult);
            }

            $verifiedVersion = $this->requireVersionFromOutput($verifyResult->output());

            if ($verifiedVersion === null) {
                return [
                    'successful' => false,
                    'exit_code' => 1,
                    'output' => 'CLI version verification failed: version output was not structured JSON.',
                    'skipped' => false,
                ];
            }

            $this->installMetadata->write(
                version: $verifiedVersion,
                binaryPath: $linkPath,
                installRoot: $installRoot,
                releasedAt: $releasedAt,
            );

            // Existing installs upgraded by `orbit update` must receive the same
            // zsh noglob integration that `bin/install-orbit` writes on first install.
            // Failure is not silent: the binary may already be replaced, but the
            // public step must not report success when shell integration failed.
            $shell = $this->ensureShellIntegrations();

            if (! $shell['successful']) {
                return [
                    'successful' => false,
                    'exit_code' => $shell['exit_code'],
                    'output' => $shell['output'],
                    'skipped' => false,
                ];
            }

            return [
                'successful' => true,
                'exit_code' => 0,
                'output' => trim($verifyResult->output()),
                'skipped' => $skipped,
            ];
        } finally {
            $this->discard($stagedPath);
        }
    }

    /**
     * Ensure supported shell integrations for existing installs that skip a binary
     * download because the CLI is already current.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function ensureShellIntegrations(): array
    {
        $result = $this->zshShellIntegration->ensure();

        if (! $this->zshShellIntegration->succeeded($result)) {
            return [
                'successful' => false,
                'exit_code' => 1,
                'output' => $result['message'] !== ''
                    ? $result['message']
                    : 'Failed to ensure zsh shell integration.',
            ];
        }

        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => '',
        ];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function verifyCurrentInstallation(string $expectedVersion): array
    {
        $linkPath = $this->resolveLinkPath();

        if (! is_file($linkPath) || ! is_executable($linkPath)) {
            return [
                'successful' => false,
                'exit_code' => 1,
                'output' => "Configured Orbit launcher is missing or not executable: {$linkPath}",
            ];
        }

        $verifyResult = $this->runCommand([$linkPath, '--version', '--local', '--json'], 30);

        if (! $verifyResult->successful()) {
            return [
                'successful' => false,
                'exit_code' => $verifyResult->exitCode() ?? 1,
                'output' => $this->resultOutput($verifyResult),
            ];
        }

        $verifiedVersion = $this->requireVersionFromOutput($verifyResult->output());

        if ($verifiedVersion !== $expectedVersion) {
            $actualVersion = $verifiedVersion ?? 'unparseable';

            return [
                'successful' => false,
                'exit_code' => 1,
                'output' => "Configured Orbit launcher reports {$actualVersion}; expected {$expectedVersion}.",
            ];
        }

        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => trim($verifyResult->output()),
        ];
    }

    /**
     * Run `orbit doctor --self --json` through the relinked launcher and return
     * the reported issue count. The doctor verify is non-fatal: any failure to
     * resolve the count yields `null` (unknown). The summary issue count lives
     * under `success.data.doctor.summary.issues` for a healthy run or
     * `error.data.doctor.summary.issues` when drift is reported.
     *
     * @return array{issues: int|null}
     */
    public function runDoctor(): array
    {
        $linkPath = $this->resolveLinkPath();
        $result = $this->runCommand([$linkPath, 'doctor', '--self', '--json'], 120);

        return ['issues' => $this->doctorIssuesFromOutput($result->output())];
    }

    private function doctorIssuesFromOutput(string $output): ?int
    {
        $output = trim($output);

        if ($output === '') {
            return null;
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $envelope = $decoded['success'] ?? $decoded['error'] ?? null;
        $data = is_array($envelope) ? $envelope['data'] ?? null : null;
        $doctor = is_array($data) ? $data['doctor'] ?? null : null;
        $summary = is_array($doctor) ? $doctor['summary'] ?? null : null;
        $issues = is_array($summary) ? $summary['issues'] ?? null : null;

        return is_int($issues) ? $issues : null;
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command, int $timeout, ?string $path = null): ProcessResult
    {
        try {
            $pending = Process::timeout($timeout);

            if ($path !== null) {
                $pending->path($path);
            }

            $process = $pending->start($command);

            while ($process->running()) {
                if (ForkedFrameTicker::hasIdleCallback()) {
                    ForkedFrameTicker::invokeIdleCallback();
                }

                usleep(ForkedFrameTicker::idleIntervalMicroseconds());

                if (method_exists($process, 'ensureNotTimedOut')) {
                    $process->ensureNotTimedOut();
                }
            }

            return $process->wait();
        } catch (Throwable $exception) {
            return Process::result(errorOutput: $exception->getMessage(), exitCode: 1);
        }
    }

    /**
     * @return array{successful: false, exit_code: int, output: string, staged_path: null, version: null}
     */
    private function failedDownload(string $output, int $exitCode): array
    {
        return [
            'successful' => false,
            'exit_code' => $exitCode,
            'output' => $output,
            'staged_path' => null,
            'version' => null,
        ];
    }

    /**
     * @return array{successful: false, exit_code: int, output: string, staged_path: null, version: null}
     */
    private function failedDownloadFrom(ProcessResult $result): array
    {
        return $this->failedDownload(
            $this->resultOutput($result),
            $result->exitCode() ?? 1,
        );
    }

    /**
     * @return array{successful: false, exit_code: int, output: string, skipped: false}
     */
    private function failedReplaceFrom(ProcessResult $result): array
    {
        return [
            'successful' => false,
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $this->resultOutput($result),
            'skipped' => false,
        ];
    }

    private function discard(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function stagedBinaryPath(string $binaryDest): string
    {
        return $binaryDest.'.download.'.bin2hex(random_bytes(8));
    }

    /**
     * @return array{successful: false, exit_code: int, output: string, skipped: false}|null
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
            'skipped' => false,
        ];
    }

    /**
     * Fail closed: successful `--version --local --json` must yield a structured
     * version. Never fall back to config('app.version') or 0.0.0 (that would
     * install/link under the wrong version path).
     */
    private function requireVersionFromOutput(string $output): ?string
    {
        return $this->versionOutputParser->fromJsonOutput($output);
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

    private function versionedBinaryMatches(string $versionedBinary, string $stagedPath): bool
    {
        if (! is_file($versionedBinary)) {
            return false;
        }

        if (! is_file($stagedPath)) {
            return true;
        }

        return hash_file('sha256', $versionedBinary) === hash_file('sha256', $stagedPath);
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        $result = $this->runCommand(
            [
                'docker',
                'exec',
                'orbit-gateway',
                'composer',
                '--working-dir=apps/gateway',
                'install',
                '--no-interaction',
            ],
            120,
            $this->checkoutPathResolver->resolve(),
        );

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $this->resultOutput($result),
        ];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array
    {
        $result = $this->runCommand(
            ['docker', 'exec', 'orbit-gateway', 'php', 'apps/gateway/artisan', 'migrate', '--force'],
            60,
            $this->checkoutPathResolver->resolve(),
        );

        return [
            'successful' => $result->successful(),
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $this->resultOutput($result),
        ];
    }

    private function resultOutput(ProcessResult $result): string
    {
        $errorOutput = $result->errorOutput();

        return trim($errorOutput !== '' ? $errorOutput : $result->output());
    }

    /**
     * Resolve the CLI binary artifact for this host.
     *
     * Priority:
     * 1. `ORBIT_BINARY_URL` with an optional `ORBIT_BINARY_SHA256`.
     * 2. The configured release manifest artifact and checksum.
     * 3. `ORBIT_BINARY_BASE_URL/<asset>`.
     * 4. The default GitHub Releases URL.
     *
     * @return array{url: string, sha256: string|null}|null
     */
    private function resolveBinaryArtifact(): ?array
    {
        $override = getenv('ORBIT_BINARY_URL');

        if (is_string($override) && $override !== '') {
            $configuredSha256 = getenv('ORBIT_BINARY_SHA256');
            $sha256 = is_string($configuredSha256) && $configuredSha256 !== ''
                ? trim($configuredSha256)
                : null;

            if ($sha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
                return null;
            }

            return ['url' => $override, 'sha256' => $sha256 === null ? null : strtolower($sha256)];
        }

        if ($this->configuredManifestUrl() !== null) {
            $platform = $this->detectCliPlatform();

            return $platform === null ? null : $this->releaseManifests->configuredCliArtifact($platform);
        }

        $asset = $this->detectBinaryAsset();

        if ($asset === null) {
            return null;
        }

        $baseUrl = getenv('ORBIT_BINARY_BASE_URL');

        if (! is_string($baseUrl) || $baseUrl === '') {
            $baseUrl = self::DEFAULT_BINARY_BASE_URL;
        }

        return ['url' => rtrim($baseUrl, '/').'/'.$asset, 'sha256' => null];
    }

    private function configuredManifestUrl(): ?string
    {
        $url = getenv('ORBIT_RELEASE_MANIFEST_URL');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return trim($url);
    }

    private function detectCliPlatform(): ?string
    {
        $os = php_uname('s');
        $machine = php_uname('m');

        if (str_starts_with($os, 'Darwin') && $machine === 'arm64') {
            return 'darwin-arm64';
        }

        if (str_starts_with($os, 'Linux') && $machine === 'x86_64') {
            return 'linux-amd64';
        }

        return null;
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
