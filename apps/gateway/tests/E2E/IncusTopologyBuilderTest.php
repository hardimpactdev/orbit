<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Support\Facades\Process;

pest()->group('e2e-provision', 'e2e-provision-superset');

it('builds the reusable superset topology from the base image', function (): void {
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
        'COPYFILE_DISABLE=1 tar --exclude=./.git --exclude=./vendor --exclude=./apps/gateway/vendor --exclude=./node_modules -czf %s -C %s .',
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

    $remoteBundle = $host->pushBundle($bundleDir);
    $passed = false;

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

        $passed = true;
    } finally {
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
