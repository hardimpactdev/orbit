<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Builds the linux x64 orbit CLI binary and stages it into a bundle directory.
 *
 * Mirrors the `test:e2e:binary:linux` composer script exactly:
 *   1. Copy orbit-core src + composer.json into apps/cli/vendor/hardimpactdev/orbit-core
 *   2. Build the .phar via `php orbit app:build` with COMPOSER_MIRROR_PATH_REPOS=1
 *   3. Pack into a linux x64 native binary via phpacker
 *   4. Assert the result is a Linux ELF x86-64 executable (throw on mismatch)
 *   5. Copy to $bundleDir/orbit-binary (chmod 0755)
 *   6. Restore apps/cli dev deps so vendor/bin tools remain available
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
    public function buildLinuxBinaryInto(string $bundleDir): void
    {
        $cliDir = repo_path('apps/cli');
        $coreDir = repo_path('packages/core');
        $orbitCoreVendorDir = "{$cliDir}/vendor/hardimpactdev/orbit-core";

        // Step 1: Inject orbit-core source directly (no vendor symlink needed).
        Process::timeout(30)->run("rm -rf {$orbitCoreVendorDir}")->throw();
        Process::timeout(30)->run("mkdir -p {$orbitCoreVendorDir}")->throw();
        Process::timeout(30)->run(sprintf(
            'cp -r %s %s %s',
            escapeshellarg("{$coreDir}/src"),
            escapeshellarg("{$coreDir}/composer.json"),
            escapeshellarg($orbitCoreVendorDir),
        ))->throw();

        // Step 2: Build the .phar.
        $buildResult = Process::timeout(300)
            ->env(['COMPOSER_MIRROR_PATH_REPOS' => '1'])
            ->path($cliDir)
            ->run('php orbit app:build orbit.phar --build-version=0.1.0 --no-interaction --timeout=0');

        if (! $buildResult->successful()) {
            throw new RuntimeException(
                "Failed to build orbit.phar: {$buildResult->output()}{$buildResult->errorOutput()}"
            );
        }

        // Step 3: Pack into a linux x64 self-contained binary.
        $phpackerResult = Process::timeout(300)
            ->path($cliDir)
            ->run('vendor/bin/phpacker build linux x64 --src=./builds/orbit.phar --php=8.5 --no-interaction');

        if (! $phpackerResult->successful()) {
            throw new RuntimeException(
                "phpacker failed: {$phpackerResult->output()}{$phpackerResult->errorOutput()}"
            );
        }

        $binarySource = "{$cliDir}/builds/dist/linux/linux-x64";

        if (! is_file($binarySource)) {
            throw new RuntimeException("Expected linux binary not found at {$binarySource}");
        }

        // Step 4: Confirm ELF before bundling.
        $fileResult = Process::timeout(10)->run(sprintf('file %s', escapeshellarg($binarySource)));
        $fileOutput = $fileResult->output();

        if (! str_contains($fileOutput, 'ELF') || ! str_contains($fileOutput, 'x86-64')) {
            throw new RuntimeException(
                "Built binary is not a Linux ELF x86-64 executable. `file` output: {$fileOutput}"
            );
        }

        // Step 5: Copy into bundle.
        $bundleBinary = self::bundledBinaryPath($bundleDir);

        if (! @copy($binarySource, $bundleBinary)) {
            throw new RuntimeException("Could not copy linux binary to bundle: {$binarySource} -> {$bundleBinary}");
        }

        chmod($bundleBinary, 0755);

        // Step 6: Restore apps/cli dev deps so vendor/bin tools (phpstan/pint/pest) remain usable.
        Process::timeout(300)
            ->path($cliDir)
            ->run('composer install --no-interaction')
            ->throw();
    }
}
