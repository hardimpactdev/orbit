<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Builds the linux x64 orbit CLI binary and stages it into a bundle directory.
 *
 * Mirrors the `test:e2e:binary:linux` composer script by calling the shared
 * root build helper, then verifies and copies the generated Linux ELF binary.
 */
final class OrbitCliBinaryBundle
{
    private const string BundledBinaryFilename = 'orbit-binary';

    public static function bundledBinaryPath(string $bundleDir): string
    {
        return rtrim($bundleDir, '/').'/'.self::BundledBinaryFilename;
    }

    /**
     * Build the linux x64 orbit binary and copy it to $bundleDir/orbit-binary.
     *
     * Throws on any failure — a failed binary build must fail the caller.
     */
    public function buildLinuxBinaryInto(string $bundleDir, ?string $fingerprint = null): void
    {
        $bundleBinary = self::bundledBinaryPath($bundleDir);
        $cacheBinary = $fingerprint !== null ? $this->cachedBinaryPath($fingerprint) : null;

        if ($cacheBinary !== null && is_file($cacheBinary)) {
            if (! @copy($cacheBinary, $bundleBinary)) {
                throw new RuntimeException(
                    "Could not copy cached linux binary to bundle: {$cacheBinary} -> {$bundleBinary}",
                );
            }

            chmod($bundleBinary, 0755);

            return;
        }

        $buildResult = Process::timeout(600)
            ->path(repo_path())
            ->run('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"');

        if (! $buildResult->successful()) {
            throw new RuntimeException(
                "Failed to build linux CLI binary: {$buildResult->output()}{$buildResult->errorOutput()}",
            );
        }

        $binarySource = repo_path('apps/cli/builds/dist/linux/linux-x64');

        if (! is_file($binarySource)) {
            throw new RuntimeException("Expected linux binary not found at {$binarySource}");
        }

        $fileResult = Process::timeout(10)->run(sprintf('file %s', escapeshellarg($binarySource)));
        $fileOutput = $fileResult->output();

        if (! str_contains($fileOutput, 'ELF') || ! str_contains($fileOutput, 'x86-64')) {
            throw new RuntimeException(
                "Built binary is not a Linux ELF x86-64 executable. `file` output: {$fileOutput}",
            );
        }

        if (! @copy($binarySource, $bundleBinary)) {
            throw new RuntimeException("Could not copy linux binary to bundle: {$binarySource} -> {$bundleBinary}");
        }

        chmod($bundleBinary, 0755);

        if ($cacheBinary !== null) {
            $cacheDir = dirname($cacheBinary);

            if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0755, true)) {
                throw new RuntimeException("Could not create CLI binary cache directory: {$cacheDir}");
            }

            if (! @copy($bundleBinary, $cacheBinary)) {
                throw new RuntimeException("Could not write cached linux binary: {$cacheBinary}");
            }

            chmod($cacheBinary, 0755);
        }
    }

    private function cachedBinaryPath(string $fingerprint): string
    {
        return $this->cacheRoot()."/cli-binaries/{$fingerprint}/".self::BundledBinaryFilename;
    }

    private function cacheRoot(): string
    {
        $xdgCacheHome = getenv('XDG_CACHE_HOME');

        if (is_string($xdgCacheHome) && $xdgCacheHome !== '') {
            return rtrim($xdgCacheHome, '/').'/orbit-e2e';
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/').'/.cache/orbit-e2e';
        }

        return sys_get_temp_dir().'/orbit-e2e-cache';
    }
}
