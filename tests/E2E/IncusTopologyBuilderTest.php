<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHostPool;
use Tests\E2E\Support\IncusTopologyBuilder;
use Tests\E2E\Support\IncusTopologyTemplate;

pest()->group('e2e-provisioning');

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

    if (! $host->imageExists($config->controlImage)) {
        $this->markTestSkipped("Required source image [{$config->controlImage}] missing on Incus host.");
    }

    $kind = E2ETopologyKind::Control;
    $templateName = IncusTopologyTemplate::templateName($kind, 'control');

    // Pre-cleanup in case a prior run left it behind.
    if ($host->instanceExists($templateName)) {
        $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
    }

    try {
        $builder = new IncusTopologyBuilder($host);
        $manifest = $builder->build($kind);

        expect($manifest)->toHaveCount(1);
        expect($manifest[0]['role'])->toBe('control');
        expect($manifest[0]['name'])->toBe($templateName);
        expect($manifest[0]['snapshot'])->toBe('clean');

        expect($host->instanceExists($templateName))->toBeTrue();
        expect($host->snapshotExists($templateName, 'clean'))->toBeTrue();
    } finally {
        if ($host->instanceExists($templateName)) {
            $host->run(sprintf('incus delete --force %s', escapeshellarg($templateName)));
        }
    }
});
