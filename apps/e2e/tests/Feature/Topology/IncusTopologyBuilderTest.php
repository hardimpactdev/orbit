<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Support\Facades\Process;

pest()->group('e2e-provision', 'e2e-provision-superset');

/**
 * Build the linux x64 orbit binary into $bundleDir/orbit-binary.
 *
 * Mirrors the `test:e2e:binary:linux` composer script exactly:
 *   1. Copy orbit-core src + composer.json into apps/cli/vendor/hardimpactdev/orbit-core
 *   2. Build the .phar via `php orbit app:build` with COMPOSER_MIRROR_PATH_REPOS=1
 *   3. Pack into a linux x64 native binary via phpacker
 *   4. Copy to $bundleDir/orbit-binary (chmod 0755)
 *   5. Confirm it is a Linux ELF binary
 *   6. Restore apps/cli dev deps so vendor/bin tools remain available
 *
 * Throws on any failure — a failed binary build must fail the test.
 */
function buildLinuxBinaryIntoBundle(string $bundleDir): void
{
    $repoRoot = repo_path();
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
    $bundleBinary = "{$bundleDir}/orbit-binary";

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

it('builds the reusable superset topology from the base image', function (): void {
    if (getenv('ORBIT_E2E') !== '1') {
        $this->markTestSkipped('Set ORBIT_E2E=1 to run the Incus provisioning superset build.');
    }

    $config = E2EConfig::fromEnvironment();

    if (! in_array('incus', $config->providerNames, true)) {
        $this->markTestSkipped('Incus provider not configured (ORBIT_E2E_PROVIDER).');
    }

    $hostPool = IncusHostPool::fromEnvironment($config);
    $host = $hostPool->first();

    if ($host === null) {
        $this->markTestSkipped('No Incus host configured (ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST).');
    }

    if (! $host->imageExists($config->baseImage)) {
        $this->markTestSkipped("Required base image [{$config->baseImage}] missing on Incus host.");
    }

    $previousArtifactNamespace = getenv(E2ETopologyArtifactNamespace::EnvironmentVariable);
    $artifactNamespace = 'provision-superset-'.getmypid().'-'.bin2hex(random_bytes(4));

    putenv(E2ETopologyArtifactNamespace::EnvironmentVariable.'='.$artifactNamespace);

    $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgent;
    $roles = IncusTopologyTemplate::rolesFor($kind);
    $templateNames = array_map(
        static fn (string $role): string => IncusTopologyTemplate::templateName($kind, $role),
        $roles,
    );
    $snapshotName = IncusTopologyTemplate::snapshotName($kind);

    // Pre-cleanup in case a prior run left it behind.
    foreach ($templateNames as $templateName) {
        if ($host->instanceExists($templateName)) {
            $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
        }
    }

    $bundleDir = sys_get_temp_dir().'/orbit-e2e-bundle-test-'.bin2hex(random_bytes(4));
    mkdir($bundleDir, 0755, true);

    $archive = "{$bundleDir}/orbit-source.tar.gz";
    Process::timeout(300)->run(sprintf(
        'COPYFILE_DISABLE=1 tar --exclude=./.git --exclude=./vendor --exclude=./apps/gateway/vendor --exclude=./apps/gateway/database/*.sqlite --exclude=./apps/gateway/database/*.sqlite-* --exclude=./apps/gateway/bootstrap/cache/*.php --exclude=./node_modules -czf %s -C %s .',
        escapeshellarg($archive),
        escapeshellarg(repo_path()),
    ))->throw();

    foreach (['install-orbit', 'e2e-provision-node', '_e2e-deps.sh'] as $script) {
        copy(repo_path('bin/'.$script), "{$bundleDir}/{$script}");
        chmod("{$bundleDir}/{$script}", 0755);
    }

    $home = (string) (getenv('HOME') ?: '');
    $composerCache = $home !== '' ? "{$home}/.cache/orbit-e2e/composer" : null;

    if ($composerCache !== null && is_dir($composerCache)) {
        mkdir("{$bundleDir}/composer-cache", 0755, true);
        Process::timeout(120)->run(sprintf(
            'cp -R %s %s',
            escapeshellarg(rtrim($composerCache, '/').'/.'),
            escapeshellarg("{$bundleDir}/composer-cache"),
        ))->throw();
    }

    // Build and bundle the linux x64 orbit binary so the VM does not need
    // gh/GH_TOKEN for the CLI binary download step during provision.
    buildLinuxBinaryIntoBundle($bundleDir);

    $remoteBundle = $host->pushBundle($bundleDir);
    $passed = false;
    $validationInstanceName = null;

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle($remoteBundle);
        $manifest = $builder->build($kind);

        expect(array_column($manifest, 'role'))->toBe($roles)
            ->and(array_column($manifest, 'name'))->toBe($templateNames)
            ->and(array_unique(array_column($manifest, 'snapshot')))->toBe([$snapshotName]);

        foreach ($templateNames as $templateName) {
            expect($host->instanceExists($templateName))->toBeTrue()
                ->and($host->snapshotExists($templateName, $snapshotName))->toBeTrue();
        }

        // --- Binary validation ---
        // Clone the operator template into a disposable validation instance,
        // start it, and confirm the installed orbit binary works.
        $operatorTemplateName = IncusTopologyTemplate::templateName($kind, 'operator');
        $validationInstanceName = 'orbit-validate-binary-'.bin2hex(random_bytes(4));

        $copyResult = $host->copyInstance(
            "{$operatorTemplateName}/{$snapshotName}",
            $validationInstanceName,
        );

        if (! $copyResult->successful()) {
            throw new RuntimeException(
                "Could not clone operator template for binary validation: {$copyResult->errorOutput()}"
            );
        }

        $startResult = $host->startInstance($validationInstanceName);

        if (! $startResult->successful()) {
            throw new RuntimeException(
                "Could not start validation instance {$validationInstanceName}: {$startResult->errorOutput()}"
            );
        }

        $validationInstance = new IncusInstance($host, $validationInstanceName);
        $validationInstance->waitForAgent();

        // Assert `orbit --version` responds with Orbit version info.
        $versionResult = $validationInstance->exec('/usr/local/bin/orbit --version', timeoutSeconds: 30);

        expect($versionResult->successful())->toBeTrue(
            "orbit --version failed: {$versionResult->output()}{$versionResult->errorOutput()}"
        );
        expect($versionResult->output())->toContain('0.1.0');

        // Assert `orbit dns:list --json` exits 0 and returns a JsonEnvelope.
        $dnsResult = $validationInstance->exec(
            'sudo -u '.$config->operatorUser.' /usr/local/bin/orbit dns:list --json',
            timeoutSeconds: 30,
        );

        expect($dnsResult->successful())->toBeTrue(
            "orbit dns:list --json failed: {$dnsResult->output()}{$dnsResult->errorOutput()}"
        );
        expect($dnsResult->output())->toContain('"success"');

        $passed = true;
    } finally {
        // Clean up the disposable validation instance regardless of pass/fail.
        if ($validationInstanceName !== null && $host->instanceExists($validationInstanceName)) {
            $host->run(sprintf('incus delete --force %s', escapeshellarg($validationInstanceName)));
        }

        $dangling = [];

        foreach ($templateNames as $templateName) {
            if ($host->instanceExists($templateName) && ($passed || ! e2eProvisionKeepsFailures())) {
                $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
            } elseif (! $passed && $host->instanceExists($templateName)) {
                $dangling[] = $templateName;
            }
        }

        if ($dangling !== []) {
            e2eProvisionReportDangling($dangling);
        }

        $host->cleanupBundle($remoteBundle);
        Process::run('rm -rf '.escapeshellarg($bundleDir));

        if (is_string($previousArtifactNamespace)) {
            putenv(E2ETopologyArtifactNamespace::EnvironmentVariable.'='.$previousArtifactNamespace);
        } else {
            putenv(E2ETopologyArtifactNamespace::EnvironmentVariable);
        }
    }
});
