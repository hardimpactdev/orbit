<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Support\Facades\Process;

pest()->group('e2e-provision');

it('builds a control topology and snapshots the template clean', function (): void {
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

    $kind = E2ETopologyKind::Operator;
    $templateName = IncusTopologyTemplate::templateName($kind, 'control');
    $snapshotName = IncusTopologyTemplate::snapshotName($kind);

    // Pre-cleanup in case a prior run left it behind.
    if ($host->instanceExists($templateName)) {
        $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
    }

    $bundleDir = sys_get_temp_dir().'/orbit-e2e-bundle-test-'.bin2hex(random_bytes(4));
    mkdir($bundleDir, 0755, true);

    $archive = "{$bundleDir}/orbit-source.tar.gz";
    Process::timeout(300)->run(sprintf(
        'COPYFILE_DISABLE=1 tar --exclude=./.git --exclude=./vendor --exclude=./node_modules -czf %s -C %s .',
        escapeshellarg($archive),
        escapeshellarg(base_path()),
    ))->throw();

    foreach (['install-orbit', 'e2e-provision-node', '_e2e-deps.sh'] as $script) {
        copy(base_path('bin/'.$script), "{$bundleDir}/{$script}");
        chmod("{$bundleDir}/{$script}", 0755);
    }

    $remoteBundle = $host->pushBundle($bundleDir);
    $passed = false;

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle($remoteBundle);
        $manifest = $builder->build($kind);

        expect($manifest)->toHaveCount(1);
        expect($manifest[0]['role'])->toBe('control');
        expect($manifest[0]['name'])->toBe($templateName);
        expect($manifest[0]['snapshot'])->toBe($snapshotName);

        expect($host->instanceExists($templateName))->toBeTrue();
        expect($host->snapshotExists($templateName, $snapshotName))->toBeTrue();

        $passed = true;
    } finally {
        if ($host->instanceExists($templateName) && ($passed || ! e2eProvisionKeepsFailures())) {
            $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
        } elseif (! $passed && $host->instanceExists($templateName)) {
            e2eProvisionReportDangling([$templateName]);
        }

        $host->cleanupBundle($remoteBundle);
        Process::run('rm -rf '.escapeshellarg($bundleDir));
    }
});
