<?php

declare(strict_types=1);

it('publishes cli artifacts gateway image and release manifest on GitHub releases', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('name: Orbit Release')
        ->toContain('types: [published]')
        ->toContain('packages: write')
        ->toContain('contents: write')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('docker buildx build')
        ->toContain('--push')
        ->toContain('--metadata-file')
        ->toContain('containerimage.digest')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->toContain('bin/orbit-release-manifest')
        ->toContain('orbit-release-manifest.json')
        ->toContain('gh release upload')
        ->toContain('orbit-linux-x64')
        ->toContain('orbit-macos-arm64')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64')
        ->not->toContain('orbit'.'-runtime');
});

it('builds cli binary workflows through the shared no-dev compressed phar helper', function (): void {
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));
    $releaseWorkflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($binaryWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');

    expect($releaseWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('builds local e2e cli binary artifacts through the shared helper', function (): void {
    $composer = json_decode((string) file_get_contents(repo_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $binaryScript = implode("\n", $composer['scripts']['test:e2e:binary']);
    $linuxBinaryScript = implode("\n", $composer['scripts']['test:e2e:binary:linux']);
    $dockerAcceptanceScript = implode("\n", $composer['scripts']['test:e2e:docker:binary-acceptance']);
    $combinedScripts = implode("\n", [$binaryScript, $linuxBinaryScript, $dockerAcceptanceScript]);

    expect($binaryScript)
        ->toContain('bin/orbit-build-cli-binary mac arm 0.1.0')
        ->toContain('ORBIT_E2E_BINARY_PATH=$(pwd)/apps/cli/builds/dist/mac/mac-arm');

    expect($linuxBinaryScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 0.1.0');

    expect($dockerAcceptanceScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 0.1.0')
        ->toContain('ORBIT_E2E_BINARY_PATH_LINUX=$(pwd)/apps/cli/builds/dist/linux/linux-x64');

    expect($combinedScripts)
        ->not->toContain('rm -rf apps/cli/vendor/hardimpactdev/orbit-core')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('documents the compressed phar runtime extension contract', function (): void {
    $documents = [
        file_get_contents(repo_path('apps/docs/content/tech-stack.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/README.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/node-concepts.md')),
    ];

    foreach ($documents as $document) {
        expect($document)
            ->toContain('pdo_sqlite')
            ->toContain('phar')
            ->toContain('zlib');
    }
});

it('keeps gateway image build hygiene covered by dockerignore in release workflow context', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));
    $dockerignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect($workflow)
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('docker/orbit-gateway/Dockerfile.dockerignore');

    expect($dockerignore)
        ->toContain('**/.env')
        ->toContain('**/vendor')
        ->toContain('storage/logs')
        ->toContain('storage/*.sqlite')
        ->toContain('node_modules')
        ->toContain('.git');
});
